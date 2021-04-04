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
    InjectableFactory,
    Loaders\Loader,
    Binding\BindingContainer,
};

use ReflectionClass;

/**
 * DI container for services. Lazy initialization is used. Services are instantiated only once.
 *
 * See https://docs.espocrm.com/development/di/.
 */
class Container
{
    private $data = [];

    private $classCache = [];

    private $loaderClassNames;

    protected $configuration = null;

    protected $injectableFactory;

    public function __construct(
        string $configurationClassName,
        array $loaderClassNames = [],
        array $services = [],
        ?BindingContainer $bindingContainer = null
    ) {
        $this->loaderClassNames = $loaderClassNames;

        foreach ($services as $name => $service) {
            if (!is_string($name) || !is_object($service)) {
                throw new Error("Container: Bad service passed.");
            }

            $this->setForced($name, $service);
        }

        $this->bindingContainer = $bindingContainer;

        $this->injectableFactory = $this->get('injectableFactory');

        $this->configuration = $this->injectableFactory->create($configurationClassName);
    }

    /**
     * Obtain a service object.
     */
    public function get(string $name) : object
    {
        if (!$this->isSet($name)) {
            $this->load($name);

            if (!$this->isSet($name)) {
                throw new Error("Could not load '{$name}' service.");
            }
        }

        return $this->data[$name];
    }

    /**
     * Check whether a service can be obtained.
     */
    public function has(string $name) : bool
    {
        if ($this->isSet($name)) {
            return true;
        }

        if (array_key_exists($name, $this->loaderClassNames)) {
            return true;
        }

        $loadMethodName = 'load' . ucfirst($name);

        if (method_exists($this, $loadMethodName)) {
            return true;
        }

        if (!$this->configuration) {
            return false;
        }

        if ($this->configuration->getLoaderClassName($name)) {
            return true;
        }

        if ($this->configuration->getServiceClassName($name)) {
            return true;
        }

        return false;
    }

    protected function isSet(string $name) : bool
    {
        return isset($this->data[$name]);
    }

    private function initClass(string $name)
    {
        if ($this->isSet($name)) {
            $object = $this->get($name);

            $this->classCache[$name] = new ReflectionClass($object);

            return;
        }

        $loadMethodName = 'load' . ucfirst($name);

        if (method_exists($this, $loadMethodName)) {
            $loaderClass = new ReflectionClass($this);

            $loadMethod = $loaderClass->getMethod($loadMethodName);

            $className = $loadMethod->getReturnType()->getName();

            $this->classCache[$name] = new ReflectionClass($className);

            return;
        }

        $loaderClassName = $this->getLoaderClassName($name);

        if ($loaderClassName) {
            $loaderClass = new ReflectionClass($loaderClassName);

            $loadMethod = $loaderClass->getMethod('load');

            if (!$loadMethod->hasReturnType()) {
                throw new Error("Loader method for service '{$name}' does not have a return type.");
            }

            $className = $loadMethod->getReturnType()->getName();

            $this->classCache[$name] = new ReflectionClass($className);

            return;
        }

        $className = $this->configuration->getServiceClassName($name);

        $this->classCache[$name] = new ReflectionClass($className);
    }

    /**
     * Get a class of a service.
     */
    public function getClass(string $name) : ReflectionClass
    {
        if (!$this->has($name)) {
            throw new Error("Service '{$name}' does not exist.");
        }

        if (!isset($this->classCache[$name])) {
            $this->initClass($name);
        }

        return $this->classCache[$name];
    }

    /**
     * Set a service object. Must be configured as settable.
     */
    public function set(string $name, object $object)
    {
        if (!$this->configuration->isSettable($name)) {
            throw new Error("Service '{$name}' is not settable.");
        }

        $this->setForced($name, $object);
    }

    protected function setForced(string $name, object $object)
    {
        $this->data[$name] = $object;
    }

    private function getLoader(string $name) : ?Loader
    {
        $loaderClassName = $this->getLoaderClassName($name);

        if (!$loaderClassName) {
            return null;
        }

        return $this->injectableFactory->create($loaderClassName);
    }

    private function getLoaderClassName(string $name) : ?string
    {
        return $this->loaderClassNames[$name] ?? $this->configuration->getLoaderClassName($name);
    }

    private function load(string $name)
    {
        $loadMethodName = 'load' . ucfirst($name);

        if (method_exists($this, $loadMethodName)) {
            $this->data[$name] = $this->$loadMethodName();

            return;
        }

        $loader = $this->getLoader($name);

        if ($loader) {
            $this->data[$name] = $loader->load();

            return;
        }

        $className = $this->configuration->getServiceClassName($name);

        if (!$className || !class_exists($className)) {
            throw new Error("Could not load '{$name}' service.");
        }

        $dependencyList = $this->configuration->getServiceDependencyList($name);

        if (!is_null($dependencyList)) {
            $dependencyObjectList = [];

            foreach ($dependencyList as $item) {
                $dependencyObjectList[] = $this->get($item);
            }

            $reflector = new ReflectionClass($className);

            $this->data[$name] = $reflector->newInstanceArgs($dependencyObjectList);

            return;
        }

        $this->data[$name] = $this->injectableFactory->create($className);
    }

    protected function loadContainer() : Container
    {
        return $this;
    }

    protected function loadInjectableFactory() : InjectableFactory
    {
        return new InjectableFactory($this, $this->bindingContainer);
    }
}
