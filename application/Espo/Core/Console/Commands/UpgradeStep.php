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

namespace Espo\Core\Console\Commands;

use Espo\Core\Container;

class UpgradeStep implements Command
{
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    protected function getContainer()
    {
        return $this->container;
    }

    public function run(array $options)
    {
        if (empty($options['step'])) {
            echo "Step is not specified.\n";
            return;
        }

        if (empty($options['id'])) {
            echo "Upgrade ID is not specified.\n";
            return;
        }

        $stepName = $options['step'];
        $upgradeId = $options['id'];

        return $this->runUpgradeStep($stepName, ['id' => $upgradeId]);
    }

    protected function runUpgradeStep($stepName, array $params)
    {
        $app = new \Espo\Core\Application();
        $app->setupSystemUser();

        $upgradeManager = new \Espo\Core\UpgradeManager($app->getContainer());

        try {
            $result = $upgradeManager->runInstallStep($stepName, $params); // throw Exception on error
        } catch (\Exception $e) {
            die("Error: " . $e->getMessage());
        }

        if (is_bool($result)) {
            $result = $result ? "true" : "false";
        }

        return $result;
    }
}
