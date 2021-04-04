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

namespace Espo\Controllers;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\Error;

class EmailAccount extends \Espo\Core\Controllers\Record
{
    public function postActionGetFolders($params, $data)
    {
        return $this->getRecordService()->getFolders([
            'host' => $data->host ?? null,
            'port' => $data->port ?? null,
            'security' =>  $data->security ?? null,
            'username' => $data->username ?? null,
            'password' => $data->password ?? null,
            'id' => $data->id ?? null,
            'emailAddress' => $data->emailAddress ?? null,
            'userId' => $data->userId ?? null,
        ]);
    }

    protected function checkControllerAccess()
    {
        if (!$this->getAcl()->check('EmailAccountScope')) {
            throw new Forbidden();
        }
    }

    public function postActionTestConnection($params, $data, $request)
    {
        if (is_null($data->password)) {
            $emailAccount = $this->getEntityManager()->getEntity('EmailAccount', $data->id);
            if (!$emailAccount || !$emailAccount->id) {
                throw new Error();
            }

            if ($emailAccount->get('assignedUserId') != $this->getUser()->id && !$this->getUser()->isAdmin()) {
                throw new Forbidden();
            }

            $data->password = $this->getContainer()->get('crypt')->decrypt($emailAccount->get('password'));
        }

        return $this->getRecordService()->testConnection(get_object_vars($data));
    }
}
