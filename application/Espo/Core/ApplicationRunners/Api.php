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

namespace Espo\Core\ApplicationRunners;

use Espo\Core\{
    InjectableFactory,
    ApplicationUser,
    Authentication\Authentication,
    Api\Auth as ApiAuth,
    Api\ErrorOutput as ApiErrorOutput,
    Api\RequestWrapper,
    Api\ResponseWrapper,
    Api\RouteProcessor,
    Utils\Route,
    Utils\Log,
};

use Slim\{
    App as SlimApp,
    Factory\AppFactory as SlimAppFactory,
};

use Psr\Http\{
    Message\ResponseInterface as Psr7Response,
    Message\ServerRequestInterface as Psr7Request,
    Server\RequestHandlerInterface as Psr7RequestHandler,
};

use Exception;
use Throwable;

/**
 * Runs API request processing.
 */
class Api implements ApplicationRunner
{
    protected $injectableFactory;
    protected $applicationUser;
    protected $routeUtil;
    protected $log;

    public function __construct(
        InjectableFactory $injectableFactory, ApplicationUser $applicationUser, Route $routeUtil, Log $log
    ) {
        $this->injectableFactory = $injectableFactory;
        $this->applicationUser = $applicationUser;
        $this->routeUtil = $routeUtil;
        $this->log = $log;
    }

    public function run()
    {
        $slim = SlimAppFactory::create();

        $slim->setBasePath(Route::detectBasePath());

        $slim->addRoutingMiddleware();

        $routeList = $this->routeUtil->getFullList();

        foreach ($routeList as $item) {
            $this->addRoute($slim, $item);
        }

        $slim->addErrorMiddleware(false, true, true, $this->log);

        $slim->run();
    }

    protected function addRoute(SlimApp $slim, array $item)
    {
        $method = strtolower($item['method']);
        $route = $item['route'];

        $slim->$method(
            $route,
            function (Psr7Request $request, Psr7Response $response, array $args) use ($item, $slim)
            {
                $routeParams = $this->getRouteParams($item, $args);

                $requestWrapped = new RequestWrapper($request, $slim->getBasePath(), $routeParams);

                $responseWrapped = new ResponseWrapper($response);

                $this->processRequest($item, $requestWrapped, $responseWrapped, $args);

                return $responseWrapped->getResponse();
            }
        );
    }

    protected function getRouteParams(array $item, array $args) : array
    {
        $params = [];

        $routeParams = $item['params'] ?? [];

        $paramKeys = array_keys($routeParams);

        $setKeyList = [];

        foreach ($paramKeys as $key) {
            $value = $routeParams[$key];

            $paramName = $key;

            if ($value[0] === ':') {
                $realKey = substr($value, 1);

                $params[$paramName] = $args[$realKey];

                $setKeyList[] = $realKey;

                continue;
            }

            $params[$paramName] = $value;
        }

        foreach ($args as $key => $value) {
            if (in_array($key, $setKeyList)) {
                continue;
            }

            $params[$key] = $value;
        }

        return $params;
    }

    protected function processRequest(array $item, RequestWrapper $requestWrapped, ResponseWrapper $responseWrapped, array $args)
    {
        try {
            $authRequired = !($item['noAuth'] ?? false);

            $authentication = $this->injectableFactory->create(Authentication::class);

            $apiAuth = ApiAuth::getBuilder()
                ->setAuthentication($authentication)
                ->setAuthRequired($authRequired)
                ->build();

            $apiAuth->process($requestWrapped, $responseWrapped);

            if (!$apiAuth->isResolved()) {
                return;
            }

            if ($apiAuth->isResolvedUseNoAuth()) {
                $this->applicationUser->setupSystemUser();
            }

            $routeProcessor = $this->injectableFactory->create(RouteProcessor::class);

            ob_start();

            $routeProcessor->process($item['route'], $requestWrapped, $responseWrapped);

            ob_clean();
        }
        catch (Exception $exception) {
            $this->handleException($exception, $requestWrapped, $responseWrapped, $item['route']);
        }
    }

    protected function handleException(
        Throwable $exception, RequestWrapper $requestWrapped, ResponseWrapper $responseWrapped, string $route
    ) {
        $errorOutput = new ApiErrorOutput($requestWrapped, $route);

        try {
            $errorOutput->process($responseWrapped, $exception);
        }
        catch (Throwable $exception) {
            $GLOBALS['log']->error($exception->getMessage());

            $responseWrapped->setStatus(500);
        }
    }
}
