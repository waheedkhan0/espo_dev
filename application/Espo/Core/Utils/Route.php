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

namespace Espo\Core\Utils;

use Espo\Core\{
    Exceptions\Error,
    Utils\Config,
    Utils\Metadata,
    Utils\File\Manager as FileManager,
    Utils\DataCache,
};

class Route
{
    protected $data = null;

    protected $cacheKey = 'routes';

    protected $paths = [
        'corePath' => 'application/Espo/Resources/routes.json',
        'modulePath' => 'application/Espo/Modules/{*}/Resources/routes.json',
        'customPath' => 'custom/Espo/Custom/Resources/routes.json',
    ];

    private $config;
    private $metadata;
    private $fileManager;
    private $dataCache;

    public function __construct(Config $config, Metadata $metadata, FileManager $fileManager, DataCache $dataCache)
    {
        $this->config = $config;
        $this->metadata = $metadata;
        $this->fileManager = $fileManager;
        $this->dataCache = $dataCache;
    }

    /**
     * Get all routes.
     */
    public function getFullList() : array
    {
        if (!isset($this->data)) {
            $this->init();
        }

        return $this->data;
    }

    protected function init()
    {
        $useCache = $this->config->get('useCache');

        if ($this->dataCache->has($this->cacheKey) && $useCache) {
            $this->data = $this->dataCache->get($this->cacheKey);

            return;
        }

        $this->data = $this->unify();

        if ($useCache) {
            $this->dataCache->store($this->cacheKey, $this->data);
        }
    }

    protected function unify() : array
    {
        $data = $this->addDataFromFile([], $this->paths['customPath']);

        $moduleData = [];

        foreach ($this->metadata->getModuleList() as $moduleName) {
            $modulePath = str_replace('{*}', $moduleName, $this->paths['modulePath']);

            foreach ($this->addDataFromFile([], $modulePath) as $row) {
                $key = $row['method'] . $row['route'];

                $moduleData[$key] = $row;
            }
        }

        $data = array_merge($data, array_values($moduleData));

        $data = $this->addDataFromFile($data, $this->paths['corePath']);

        return $data;
    }

    protected function addDataFromFile(array $currentData, string $routeFile) : array
    {
        if (!file_exists($routeFile)) {
            return $currentData;
        }

        $content = $this->fileManager->getContents($routeFile);

        $data = Json::getArrayData($content);

        if (empty($data)) {
            $GLOBALS['log']->warning("Route: No data or syntax error in '{$routeFile}'.");

            return $currentData;
        }

        return $this->appendRoutesToData($currentData, $data);
    }

    protected function appendRoutesToData(array $data, array $newData) : array
    {
        foreach ($newData as $route) {
            $route['route'] = $this->adjustPath($route['route']);

            if (isset($route['conditions'])) {
                $route['noAuth'] = !($route['conditions']['auth'] ?? true);

                unset($route['conditions']);
            }

            if (self::isRouteInList($route, $data)) {
                continue;
            }

            $data[] = $route;
        }

        return $data;
    }

    /**
     * Check and adjust the route path.
     */
    protected function adjustPath(string $routePath) : string
    {
        $routePath = trim($routePath);

        // to fast route format
        $routePath = preg_replace('/\:([a-zA-Z0-9]+)/', '{${1}}', $routePath);

        if (substr($routePath, 0, 1) !== '/') {
            return '/' . $routePath;
        }

        return $routePath;
    }

    public static function detectBasePath() : string
    {
        $scriptName = parse_url($_SERVER['SCRIPT_NAME'] , PHP_URL_PATH);
        $scriptDir = dirname($scriptName);

        $uri = parse_url('http://any.com' . $_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (stripos($uri, $scriptName) === 0) {
            return $scriptName;
        }

        if ($scriptDir !== '/' && stripos($uri, $scriptDir) === 0) {
            return $scriptDir;
        }

        return '';
    }

    public static function detectEntryPointRoute() : string
    {
        $basePath = self::detectBasePath();

        $uri = parse_url('http://any.com' . $_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if ($uri === $basePath) {
            return '/';
        }

        if (stripos($uri, $basePath) === 0) {
            return substr($uri, strlen($basePath));
        }

        return '/';
    }

    static protected function isRouteInList(array $newRoute, array $routeList) : bool
    {
        foreach ($routeList as $route) {
            if (Util::isEquals($route, $newRoute)) {
                return true;
            }
        }

        return false;
    }
}
