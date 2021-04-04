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
    ContainerBuilder,
    InjectableFactory,
    Container,
    ApplicationUser,
    Utils\Autoload,
    Utils\Config,
    Utils\Metadata,
    Utils\ClientManager,
    Utils\Log,
};

use ReflectionClass;
use StdClass;

/**
 * A central access point of the application.
 */
class Application
{
    protected $container;

    public function __construct()
    {
        date_default_timezone_set('UTC');

        $this->initContainer();
        $this->initAutoloads();
        $this->initPreloads();
    }

    protected function initContainer()
    {
        $this->container = (new ContainerBuilder())->build();
    }

    /**
     * Run a specific application runner.
     */
    public function run(string $className, ?StdClass $params = null)
    {
        if (!$className || !class_exists($className)) {
            $this->getLog()->error("Application runner '{$className}' does not exist.");

            return;
        }

        $class = new ReflectionClass($className);

        if ($class->getStaticPropertyValue('cli', false)) {
            if (substr(php_sapi_name(), 0, 3) !== 'cli') {
                die("Can be run only via CLI.");
            }
        }

        if ($class->getStaticPropertyValue('setupSystemUser', false)) {
            $this->setupSystemUser();
        }

        $runner = $this->getInjectableFactory()->createWith($className, [
            'params' => $params,
        ]);

        $runner->run();
    }

    /**
     * Whether an application is installed.
     */
    public function isInstalled() : bool
    {
        $config = $this->getConfig();

        if (file_exists($config->getConfigPath()) && $config->get('isInstalled')) {
            return true;
        }

        return false;
    }

    /**
     * Get a service container.
     */
    public function getContainer() : Container
    {
        return $this->container;
    }

    protected function getInjectableFactory() : InjectableFactory
    {
        return $this->container->get('injectableFactory');
    }

    protected function getApplicationUser() : ApplicationUser
    {
        return $this->container->get('applicationUser');
    }

    protected function getLog() : Log
    {
        return $this->container->get('log');
    }

    protected function getClientManager() : ClientManager
    {
        return $this->container->get('clientManager');
    }

    protected function getMetadata() : Metadata
    {
        return $this->container->get('metadata');
    }

    protected function getConfig() : Config
    {
        return $this->container->get('config');
    }

    protected function initAutoloads()
    {
        $autoload = $this->getInjectableFactory()->create(Autoload::class);

        $autoload->register();
    }

    /**
     * Initialize services that has the 'preload' parameter.
     */
    protected function initPreloads()
    {
        foreach ($this->getMetadata()->get(['app', 'containerServices']) ?? [] as $name => $defs) {
            if ($defs['preload'] ?? false) {
                $this->container->get($name);
            }
        }
    }

    /**
     * Set a base path of an index file related to the application directory. Used for a portal.
     */
    public function setClientBasePath(string $basePath)
    {
        $this->getClientManager()->setBasePath($basePath);
    }

    /**
     * Setup the system user. The system user is used when no user is logged in.
     */
    public function setupSystemUser()
    {
        $this->getApplicationUser()->setupSystemUser();
    }
}
