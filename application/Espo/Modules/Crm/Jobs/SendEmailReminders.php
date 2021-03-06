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

namespace Espo\Modules\Crm\Jobs;

use Espo\Core\{
    InjectableFactory,
    ORM\EntityManager,
    Jobs\Job,
};

use Espo\Modules\Crm\Business\Reminder\EmailReminder;

class SendEmailReminders implements Job
{
    const MAX_PORTION_SIZE = 10;

    protected $injectableFactory;
    protected $entityManager;

    public function __construct(InjectableFactory $injectableFactory, EntityManager $entityManager)
    {
        $this->injectableFactory = $injectableFactory;
        $this->entityManager = $entityManager;
    }

    public function run()
    {
        $dt = new \DateTime();

        $now = $dt->format('Y-m-d H:i:s');
        $nowShifted = $dt->sub(new \DateInterval('PT1H'))->format('Y-m-d H:i:s');

        $collection = $this->entityManager->getRepository('Reminder')->where([
            'type' => 'Email',
            'remindAt<=' => $now,
            'startAt>' => $nowShifted,
        ])->find();

        if (empty($collection)) {
            return;
        }

        $emailReminder = $this->injectableFactory->create(EmailReminder::class);

        $pdo = $this->entityManager->getPDO();

        foreach ($collection as $i => $entity) {
            if ($i >= self::MAX_PORTION_SIZE) {
                break;
            }
            try {
                $emailReminder->send($entity);
            } catch (\Exception $e) {
                $GLOBALS['log']->error('Job SendEmailReminders '.$entity->id.': [' . $e->getCode() . '] ' .$e->getMessage());
            }
            $this->entityManager->getRepository('Reminder')->deleteFromDb($entity->id);
        }
    }
}
