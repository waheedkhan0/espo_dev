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

use Espo\Core\CronManager;
use Espo\Core\Utils\Util;
use Espo\Core\ORM\EntityManager;

class RunJob implements Command
{
    protected $cronManager;
    protected $entityManager;

    public function __construct(CronManager $cronManager, EntityManager $entityManager)
    {
        $this->cronManager = $cronManager;
        $this->entityManager = $entityManager;
    }

    public function run(array $options, array $flags, array $argumentList)
    {
        $jobName = $options['job'] ?? null;
        $targetId = $options['targetId'] ?? null;
        $targetType = $options['targetType'] ?? null;

        if (!$jobName && count($argumentList)) {
            $jobName = $argumentList[0];
        }

        if (!$jobName) echo "No job specified.\n";

        $jobName = ucfirst(Util::hyphenToCamelCase($jobName));

        $entityManager = $this->entityManager;

        $job = $entityManager->createEntity('Job', [
            'name' => $jobName,
            'job' => $jobName,
            'targetType' => $targetType,
            'targetId' => $targetId,
        ]);

        $result = $this->cronManager->runJob($job);

        if ($result) {
            echo "Job '{$jobName}' has been executed.\n";
        } else {
            echo "Job '{$jobName}' failed to execute.\n";
        }
    }
}
