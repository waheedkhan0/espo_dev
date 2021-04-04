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

namespace Espo\Services;

use Espo\ORM\Entity;

use Espo\Core\Exceptions\Error;

class EmailAddress extends Record
{
    const ERASED_PREFIX = 'ERASED:';

    protected function findInAddressBookByEntityType($query, $limit, $entityType, &$result, $onlyActual = false)
    {
        $whereClause = [
            'OR' => [
                [
                    'name*' => $query . '%'
                ],
                [
                    'emailAddress*' => $query . '%'
                ]
            ],
            [
                'emailAddress!=' => null
            ]
        ];

        $selectParams = [
            'whereClause' => $whereClause,
            'orderBy' => 'name',
            'limit' => $limit
        ];

        $handleSelectParamsMethodName = 'handleSelectParams' . $entityType;
        if (method_exists($this, $handleSelectParamsMethodName)) {
            $this->$handleSelectParamsMethodName($query, $selectParams);
        }

        $selectManager = $this->getSelectManagerFactory()->create($entityType);
        $selectManager->applyAccess($selectParams);

        $select = ['id', 'emailAddress', 'name'];

        if ($this->getMetadata()->get(['entityDefs', $entityType, 'fields', 'name', 'type']) === 'personName') {
            $select[] = 'firstName';
            $select[] = 'lastName';
        }

        $collection = $this->getEntityManager()->getRepository($entityType)
            ->select($select)
            ->find($selectParams);

        foreach ($collection as $entity) {
            $emailAddress = $entity->get('emailAddress');
            $emailAddressData = $this->getEntityManager()->getRepository('EmailAddress')->getEmailAddressData($entity);

            $skipPrimaryEmailAddress = false;

            if ($emailAddress) {
                if (strpos($emailAddress, self::ERASED_PREFIX) === 0) $skipPrimaryEmailAddress = true;

                if ($onlyActual) {
                    if ($entity->get('emailAddressIsOptedOut')) $skipPrimaryEmailAddress = true;

                    foreach ($emailAddressData as $item) {
                        if ($emailAddress !== $item->emailAddress) continue;
                        if (!empty($item->invalid)) $skipPrimaryEmailAddress = true;
                    }
                }
            }

            if (!$skipPrimaryEmailAddress) {
                $result[] = [
                    'emailAddress' => $emailAddress,
                    'entityName' => $entity->get('name'),
                    'entityType' => $entityType,
                    'entityId' => $entity->id
                ];
            }

            foreach ($emailAddressData as $item) {
                if ($emailAddress === $item->emailAddress) continue;

                if (strpos($item->emailAddress, self::ERASED_PREFIX) === 0) continue;

                if ($onlyActual) {
                    if (!empty($item->invalid)) continue;
                    if (!empty($item->optOut)) continue;
                }

                $result[] = [
                    'emailAddress' => $item->emailAddress,
                    'entityName' => $entity->get('name'),
                    'entityType' => $entityType,
                    'entityId' => $entity->id
                ];
            }
        }
    }

    protected function handleSelectParamsUser($query, &$selectParams)
    {
        if ($this->getAcl()->get('portalPermission') === 'no') {
            $selectParams['whereClause'][] = [
                'type!=' => 'portal'
            ];
        }
        $selectParams['whereClause'][] = [
            'type!=' => ['api', 'system', 'super-admin']
        ];
    }

    protected function findInInboundEmail($query, $limit, &$result)
    {
        if ($this->getUser()->isPortal()) {
            return [];
        }

        $list = $this->getEntityManager()->getRepository('InboundEmail')
            ->select(['id', 'name', 'emailAddress'])
            ->where([
                'emailAddress*' => $query . '%',
            ])
            ->order('name')
            ->find();

        foreach ($list as $item) {
            $result[] = [
                'emailAddress' => $item->get('emailAddress'),
                'entityName' => $item->get('name'),
                'entityType' => 'InboundEmail',
                'entityId' => $item->get('id'),
            ];
        }
    }

    public function searchInAddressBook($query, $limit, $onlyActual = false)
    {
        $result = [];

        $entityTypeList = $this->getConfig()->get('emailAddressLookupEntityTypeList') ?? [];
        $allEntityTypeList = $this->getHavingEmailAddressEntityTypeList();

        foreach ($entityTypeList as $entityType) {
            if (!in_array($entityType, $allEntityTypeList)) continue;
            if (!$this->getAcl()->checkScope($entityType)) continue;
            $this->findInAddressBookByEntityType($query, $limit, $entityType, $result, $onlyActual);
        }

        $this->findInInboundEmail($query, $limit, $result);

        $finalResult = [];

        foreach ($result as $item) {
            foreach ($finalResult as $item1) {
                if ($item['emailAddress'] == $item1['emailAddress']) {
                    continue 2;
                }
            }
            $finalResult[] = $item;
        }

        usort($finalResult, function ($item1, $item2) use ($query) {
            if (strpos($query, '@') === false) return 0;

            $p1 = strpos($item1['emailAddress'], $query);
            $p2 = strpos($item2['emailAddress'], $query);

            if ($p1 === 0 && $p2 !== 0) return -1;
            if ($p1 !== 0 && $p2 !== 0) return 0;
            if ($p1 !== 0 && $p2 === 0) return 1;

            return 0;
        });

        return $finalResult;
    }

    protected function getHavingEmailAddressEntityTypeList()
    {
        $list = ['Account', 'Contact', 'Lead', 'User'];

        $scopeDefs = $this->getMetadata()->get(['scopes']);
        foreach ($scopeDefs as $scope => $defs) {
            if (empty($defs['disabled']) && !empty($defs['type']) && ($defs['type'] === 'Person' || $defs['type'] === 'Company')) {
                $list[] = $scope;
            }
        }
        return $list;
    }
}
