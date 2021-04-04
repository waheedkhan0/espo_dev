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

use Espo\Core\Exceptions\NotFound;

use Espo\Core\{
    InjectableFactory,
    Utils\ClassFinder,
    Api\Request,
    Api\Response,
};

use StdClass;

/**
 * Runs entry points.
 */
class EntryPointManager
{
    protected $injectableFactory;
    protected $classFinder;

    public function __construct(InjectableFactory $injectableFactory, ClassFinder $classFinder)
    {
        $this->injectableFactory = $injectableFactory;
        $this->classFinder = $classFinder;
    }

    public function checkAuthRequired(string $name) : bool
    {
        $className = $this->getClassName($name);

        if (!$className) {
            throw new NotFound("EntryPoint {$name} not found.");
        }

        if ($className::$noAuth ?? false) {
            return false;
        }

        // for backward compatibility
        return $className::$authRequired ?? true;
    }

    public function checkNotStrictAuth(string $name) : bool
    {
        $className = $this->getClassName($name);

        if (!$className) {
            throw new NotFound("EntryPoint {$name} not found.");
        }

        return $className::$notStrictAuth ?? false;
    }

    public function run(string $name, Request $request, Response $response, ?StdClass $data = null)
    {
        $className = $this->getClassName($name);

        if (!$className) {
            throw new NotFound("EntryPoint {$name} not found.");
        }

        $entryPoint = $this->injectableFactory->create($className);

        $entryPoint->run($request, $response, $data);
    }

    protected function getClassName(string $name) : ?string
    {
        $name = ucfirst($name);

        return $this->classFinder->find('EntryPoints', $name);
    }
}
