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
    Container,
    ContainerConfiguration,
    Binding\BindingContainer,
    Binding\BindingLoader,
    Binding\EspoBindingLoader,
    Utils\File\Manager as FileManager,
    Utils\Config\ConfigFileManager,
    Utils\Config,
    Utils\DataCache,
    Utils\Module,
    Loaders\Log as LogLoader,
    Loaders\DataManager as DataManagerLoader,
    Loaders\Metadata as MetadataLoader,
};

/**
 * Builds a service container.
 */
class ContainerBuilder
{
    private $containerClassName = Container::class;

    private $containerConfigurationClassName = ContainerConfiguration::class;

    private $configClassName = Config::class;

    private $fileManagerClassName = FileManager::class;

    private $dataCacheClassName = DataCache::class;

    private $bindingLoader = null;

    private $services = [];

    protected $loaderClassNames = [
        'log' => LogLoader::class,
        'dataManager' => DataManagerLoader::class,
        'metadata' => MetadataLoader::class,
    ];

    public function withBindingLoader(BindingLoader $bindingLoader) : self
    {
        $this->bindingLoader = $bindingLoader;

        return $this;
    }

    public function withServices(array $services) : self
    {
        foreach ($services as $key => $value) {
            $this->services[$key] = $value;
        }

        return $this;
    }

    public function withLoaderClassNames(array $classNames) : self
    {
        foreach ($classNames as $key => $value) {
            $this->loaderClassNames[$key] = $value;
        }

        return $this;
    }

    public function withContainerClassName(string $containerClassName) : self
    {
        $this->containerClassName = $containerClassName;

        return $this;
    }

    public function withContainerConfigurationClassName(string $containerConfigurationClassName) : self
    {
        $this->containerConfigurationClassName = $containerConfigurationClassName;

        return $this;
    }

    public function withConfigClassName(string $configClassName) : self
    {
        $this->configClassName = $configClassName;

        return $this;
    }

    public function withFileManagerClassName(string $fileManagerClassName) : self
    {
        $this->fileManagerClassName = $fileManagerClassName;

        return $this;
    }

    public function withDataCacheClassName(string $dataCacheClassName) : self
    {
        $this->dataCacheClassName = $dataCacheClassName;

        return $this;
    }

    public function build() : Container
    {
        $config = $this->services['config'] ?? (
            new $this->configClassName(
                new ConfigFileManager()
            )
        );

        $fileManager = $this->services['fileManager'] ?? (
            new $this->fileManagerClassName(
                $config->get('defaultPermissions')
            )
        );

        $dataCache = $this->services['dataCache'] ?? (
            new $this->dataCacheClassName($fileManager)
        );

        $this->services['config'] = $config;
        $this->services['fileManager'] = $fileManager;
        $this->services['dataCache'] = $dataCache;

        $useCache = $config->get('useCache') ?? false;

        $bindingLoader = $this->bindingLoader ?? (
            new EspoBindingLoader(
                new Module($fileManager, $dataCache, $useCache)
            )
        );

        $bindingContainer = new BindingContainer($bindingLoader);

        return new $this->containerClassName(
            $this->containerConfigurationClassName, $this->loaderClassNames, $this->services, $bindingContainer
        );
    }
}
