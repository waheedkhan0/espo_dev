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

namespace Espo\Modules\Crm\SelectManagers;

class Call extends \Espo\Core\Select\SelectManager
{
    protected $selectAttributesDependancyMap = [
        'duration' => [
            'dateStart',
            'dateEnd',
        ],
    ];

    protected function accessOnlyOwn(&$result)
    {
        $this->setDistinct(true, $result);
        $this->addJoin(['users', 'usersAccess'], $result);
        $result['whereClause'][] = [
            'OR' => [
                'usersAccessMiddle.userId' => $this->getUser()->id,
                'assignedUserId' => $this->getUser()->id
            ]
        ];
    }

    protected function accessOnlyTeam(&$result)
    {
        $this->setDistinct(true, $result);
        $this->addLeftJoin(['teams', 'teamsAccess'], $result);
        $this->addLeftJoin(['users', 'usersAccess'], $result);

        $result['whereClause'][] = [
            'OR' => [
                'teamsAccessMiddle.teamId' => $this->getUser()->getLinkMultipleIdList('teams'),
                'usersAccessMiddle.userId' => $this->getUser()->id,
                'assignedUserId' => $this->getUser()->id
            ]
        ];
    }

    protected function boolFilterOnlyMy(&$result)
    {
        $this->addLeftJoin(['users', 'usersFilterOnlyMy'], $result);
        return [
            'usersFilterOnlyMyMiddle.userId' => $this->getUser()->id,
            'OR' => [
                'usersFilterOnlyMyMiddle.status!=' => 'Declined',
                'usersFilterOnlyMyMiddle.status=' => null
            ]
        ];
    }

    protected function filterPlanned(&$result)
    {
        $result['whereClause'][] = array(
        	'status' => 'Planned'
        );
    }

    protected function filterHeld(&$result)
    {
        $result['whereClause'][] = array(
        	'status' => 'Held'
        );
    }

    protected function filterTodays(&$result)
    {
        $result['whereClause'][] = $this->convertDateTimeWhere(array(
        	'type' => 'today',
        	'field' => 'dateStart',
        	'timeZone' => $this->getUserTimeZone()
        ));
    }
}
