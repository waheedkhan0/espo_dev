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

namespace Espo\Core\Api;

use Espo\Core\{
    Utils\Config,
    Utils\Json,
    ControllerManager,
    Exceptions\Error,
};

use StdClass;

/**
 * Processes routes. Obtains a controller name, action, body from a request. Then passes them to controller manager.
 */
class RouteProcessor
{
    protected $config;
    protected $controllerManager;

    public function __construct(Config $config, ControllerManager $controllerManager)
    {
        $this->config = $config;
        $this->controllerManager = $controllerManager;
    }

    public function process(string $route, Request $request, Response $response)
    {
        $response->setHeader('Content-Type', 'application/json');

        $params = $request->getRouteParams();

        $controllerName = $params['controller'] ?? null;
        $actionName = $params['action'] ?? null;

        if (!$controllerName) {
            throw new Error("Route '{$route}' doesn't have a controller.");
        }

        $controllerName = ucfirst($controllerName);

        $requestMethod = $request->getMethod();

        if (!$actionName) {
            $httpMethod = strtolower($requestMethod);

            $crudList = $this->config->get('crud') ?? [];

            $actionName = $crudList[$httpMethod] ?? null;

            if (!$actionName) {
                throw new Error("No action for method {$httpMethod}.");
            }
        }

        $result = $this->controllerManager->process($controllerName, $actionName, $request, $response) ?? null;

        $responseContents = $result;

        if (
            is_int($result) ||
            is_float($result) ||
            is_array($result) ||
            is_bool($result) ||
            $result instanceof StdClass
        ) {
            $responseContents = Json::encode($result);
        }

        if (is_string($responseContents)) {
            $response->writeBody($responseContents);
        }

        $response->setHeader('Expires', '0');
        $response->setHeader('Last-Modified', gmdate("D, d M Y H:i:s") . " GMT");
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
        $response->setHeader('Pragma', 'no-cache');
    }
}
