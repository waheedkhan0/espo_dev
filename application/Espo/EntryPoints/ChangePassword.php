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

namespace Espo\EntryPoints;

use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;

use Espo\Core\EntryPoints\{
    EntryPoint,
    NoAuth,
};

use Espo\Core\{
    Utils\Config,
    Utils\ClientManager,
    ORM\EntityManager,
    Api\Request,
};

class ChangePassword implements EntryPoint
{
    use NoAuth;

    protected $config;
    protected $clientManager;
    protected $entityManager;

    public function __construct(Config $config, ClientManager $clientManager, EntityManager $entityManager)
    {
        $this->config = $config;
        $this->clientManager = $clientManager;
        $this->entityManager = $entityManager;
    }

    public function run(Request $request)
    {
        $requestId = $request->get('id');

        if (!$requestId) throw new BadRequest();

        $config = $this->config;

        $request = $this->entityManager->getRepository('PasswordChangeRequest')->where([
            'requestId' => $requestId,
        ])->findOne();

        $strengthParams = [
            'passwordStrengthLength' => $this->config->get('passwordStrengthLength'),
            'passwordStrengthLetterCount' => $this->config->get('passwordStrengthLetterCount'),
            'passwordStrengthNumberCount' => $this->config->get('passwordStrengthNumberCount'),
            'passwordStrengthBothCases' => $this->config->get('passwordStrengthBothCases'),
        ];

        if (!$request) throw new NotFound();

        $options = [
            'id' => $requestId,
            'strengthParams' => $strengthParams,
        ];

        $runScript = "
            app.getController('PasswordChangeRequest', function (controller) {
                controller.doAction('passwordChange', ".json_encode($options).");
            });
        ";

        $this->clientManager->display($runScript);
    }
}
