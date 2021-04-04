<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core;

use Espo\Core\{
    Exceptions\Error,
    Interfaces\Injectable,
    Binding\BindingContainer,
    Binding\BindingLoader,
    Binding\Binding,
};

use ReflectionClass;
use ReflectionParameter;
use ReflectionFunction;
use Throwable;

/**
 * Creates an instance by a class name. Uses constructor param names and type hinting to detect which
 * dependencies are needed. Service dependencies are instantiated only once. Non-service dependencies
 * are instantiated every time along with a dependent class.
 *
 * Aware interfaces are also used to detect service dependencies.
 */
class InjectableFactory
{
    protected $container;

    protected $bindingContainer;

    public function __construct(Container $container, ?BindingContainer $bindingContainer = null)
    {
        $this->container = $container;
        $this->bindingContainer = $bindingContainer;
    }

    /**
     * Creates an instance by a class name.
     */
    public function create(string $className) : object
    {
        return $this->createByClassName($className);
    }

    /**
     * Creates an instance by a class name. Allows passing specific constructor parameters.
     * Defined in an associative array. A key should match the parameter name.
     */
    public function createWith(string $className, array $with = []) : object
    {
        return $this->createByClassName($className, $with);
    }

    /**
     * @deprecated Use create or createWith methods instead. Left public for backward compatibility.
     * @todo Make protected.
     */
    public function createByClassName(string $className, ?array $with = null) : object
    {
        if (!class_exists($className)) {
            throw new Error("InjectableFactory: Class '{$className}' does not exist.");
        }

        $class = new ReflectionClass($className);

        $injectionList = $this->getConstructorInjectionList($class, $with);

        $obj = $class->newInstanceArgs($injectionList);

        // @todo Remove in 6.4.
        if ($class->implementsInterface(Injectable::class)) {
            $this->applyInjectable($class, $obj);

            return $obj;
        }

        $this->applyAwareInjections($class, $obj);

        return $obj;
    }

    /**
     * @deprecated
     * @todo Remove in 6.4.
     */
    protected function applyInjectable(ReflectionClass $class, object $obj)
    {
        $setList = [];

        $dependencyList = $obj->getDependencyList();

        foreach ($dependencyList as $name) {
            $injection = $this->container->get($name);

            if ($this->classHasDependencySetter($class, $name)) {
                $methodName = 'set' . ucfirst($name);
                $obj->$methodName($injection);
                $setList[] = $name;
            }

            $obj->inject($name, $injection);
        }

        $this->applyAwareInjections($class, $obj, $setList);

        return $obj;
    }

    protected function getConstructorInjectionList(ReflectionClass $class, ?array $with = null) : array
    {
        $injectionList = [];

        $constructor = $class->getConstructor();

        if (!$constructor) {
            return $injectionList;
        }

        $params = $constructor->getParameters();

        foreach ($params as $param) {
            $injectionList[] = $this->getMethodParamInjection($class, $param, $with);
        }

        return $injectionList;
    }

    protected function getMethodParamInjection(?ReflectionClass $class, ReflectionParameter $param, ?array $with)
    {
        $name = $param->getName();

        if ($with && array_key_exists($name, $with)) {
            return $with[$name];
        }

        $dependencyClass = null;

        $type = $param->getType();

        if ($type && !$type->isBuiltin()) {
            try {
                $dependencyClass = new ReflectionClass($type->getName());
            }
            catch (Throwable $e) {
                $badClassName = $param->getType()->getName();

                // This trick allows to log syntax errors.
                class_exists($badClassName);

                throw new Error("InjectableFactory: " . $e->getMessage());
            }
        }

        if ($this->bindingContainer && $this->bindingContainer->has($class, $param)) {
            $binding = $this->bindingContainer->get($class, $param);

            return $this->resolveBinding($binding);
        }

        if (!$dependencyClass && $param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if (
            $dependencyClass && $this->container->has($name) &&
            $this->areDependencyClassesMatching($dependencyClass, $this->container->getClass($name))
        ) {
            return $this->container->get($name);
        }

        if ($dependencyClass && $param->allowsNull()) {
            return null;
        }

        if ($dependencyClass) {
            return $this->create($dependencyClass->getName());
        }

        if (!$class) {
            throw new Error("InjectableFactory: Could not resolve the dependency '{$name}' for a callback.");
        }

        $className = $class->getName();

        throw new Error("InjectableFactory: Could not create '{$className}', the dependency '{$name}' is not resolved.");
    }

    protected function getCallbackInjectionList(callable $callback, ?array $with = null) : array
    {
        $injectionList = [];

        $function = new ReflectionFunction($callback);

        foreach ($function->getParameters() as $param) {
            $injectionList[] = $this->getMethodParamInjection(null, $param, $with);
        }

        return $injectionList;
    }

    protected function resolveBinding(Binding $binding)
    {
        $type = $binding->getType();
        $value = $binding->getValue();

        if ($type === Binding::CONTAINER_SERVICE) {
            return $this->container->get($value);
        }

        if ($type === Binding::IMPLEMENTATION_CLASS_NAME) {
            return $this->create($value);
        }

        if ($type === Binding::VALUE) {
            return $value;
        }

        if ($type === Binding::CALLBACK) {
            $callback = $value;

            $dependencyList = $this->getCallbackInjectionList($callback);

            return $callback(...$dependencyList);
        }

        throw new Error("InjectableFactory: Bad binding.");
    }

    protected function areDependencyClassesMatching(ReflectionClass $paramHintClass, ReflectionClass $returnHintClass) : bool
    {
        if ($paramHintClass->getName() === $returnHintClass->getName()) {
            return true;
        }

        if ($returnHintClass->isSubclassOf($paramHintClass)) {
            return true;
        }

        return false;
    }

    protected function applyAwareInjections(ReflectionClass $class, object $obj, array $ignoreList = [])
    {
        foreach ($class->getInterfaces() as $interface) {
            $interfaceName = $interface->getShortName();

            if (substr($interfaceName, -5) !== 'Aware' || strlen($interfaceName) <= 5) {
                continue;
            }

            $name = lcfirst(substr($interfaceName, 0, -5));

            if (in_array($name, $ignoreList)) {
                continue;
            }

            if (!$this->classHasDependencySetter($class, $name, true)) {
                continue;
            }

            $injection = $this->container->get($name);

            $methodName = 'set' . ucfirst($name);
            $obj->$methodName($injection);
        }
    }

    protected function classHasDependencySetter(ReflectionClass $class, string $name, bool $skipInstanceCheck = false) : bool
    {
        $methodName = 'set' . ucfirst($name);

        if (!$class->hasMethod($methodName) || !$class->getMethod($methodName)->isPublic()) {
            return false;
        }

        $params = $class->getMethod($methodName)->getParameters();

        if (!$params || !count($params)) {
            return false;
        }

        if ($skipInstanceCheck) {
            return true;
        }

        $injection = $this->container->get($name);

        $paramClass = null;

        $type = $params[0]->getType();

        if ($type && !$type->isBuiltin()) {
            $paramClass = new ReflectionClass($type->getName());
        }

        if ($paramClass && $paramClass->isInstance($injection)) {
            return true;
        }

        return false;
    }
}
