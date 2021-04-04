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

namespace Espo\SelectManagers;

class Email extends \Espo\Core\Select\SelectManager
{
    protected $textFilterUseContainsAttributeList = ['name'];

    protected $fullTextOrderType = self::FT_ORDER_ORIGINAL;

    protected $selectAttributesDependancyMap = [
        'subject' => ['name'],
        'personStringData' => ['fromString', 'fromEmailAddressId'],
    ];

    public function applyAdditional(array $params, array &$result)
    {
        parent::applyAdditional($params, $result);

        $folderId = $params['folderId'] ?? null;

        if ($folderId) {
            $this->applyFolder($folderId, $result);
        }

        $textFilter = $params['textFilter'] ?? null;

        if (!$textFilter && $this->hasInOrderBy('dateSent', $params)) {
            $skipIndex = false;
            if (isset($params['where'])) {
                foreach ($params['where'] as $item) {
                    $type = $item['type'] ?? null;
                    $value = $item['value'] ?? null;
                    if ($type === 'textFilter') {
                        $skipIndex = true;
                        break;
                    } else {
                        if (isset($item['attribute'])) {
                            if (!in_array($item['attribute'], ['teams', 'users', 'status'])) {
                                $skipIndex = true;
                                break;
                            }
                        }
                    }
                }
            }
            if ($folderId === 'important' || $folderId === 'drafts') {
                $skipIndex = true;
            }

            /*$actualDatabaseType = strtolower($this->getConfig()->get('actualDatabaseType'));
            $actualDatabaseVersion = $this->getConfig()->get('actualDatabaseVersion');

            if (
                !$skipIndex &&
                ($actualDatabaseType !== 'mysql' || version_compare($actualDatabaseVersion, '8.0.0') < 0) &&
                $this->hasLinkJoined('teams', $result)
            ) {
                $skipIndex = true;
            }*/

            if ($this->hasLinkJoined('teams', $result)) {
                $skipIndex = true;
            }

            if (!$skipIndex) {
                $result['useIndex'] = 'dateSent';
            }
        }

        if ($folderId === 'drafts') {
            $result['useIndex'] = 'createdById';
        }

        if ($folderId !== 'drafts') {
            $this->addUsersJoin($result);
        }

        return $result;
    }

    public function applyFolder(?string $folderId, array &$result)
    {
        switch ($folderId) {
            case 'all':
                break;
            case 'inbox':
                $this->filterInbox($result);
                break;
            case 'important':
                $this->filterImportant($result);
                break;
            case 'sent':
                $this->filterSent($result);
                break;
            case 'trash':
                $this->filterTrash($result);
                break;
            case 'drafts':
                $this->filterDrafts($result);
                break;
            default:
                $this->applyEmailFolder($folderId, $result);
        }
    }

    public function addUsersJoin(array &$result)
    {
        if (!$this->hasJoin('users', $result) && !$this->hasLeftJoin('users', $result)) {
            $this->addLeftJoin('users', $result);
        }

        $this->setJoinCondition('users', [
            'userId' => $this->getUser()->id
        ], $result);

        $this->addUsersColumns($result);
    }

    protected function applyEmailFolder($folderId, &$result)
    {
        $result['whereClause'][] = [
            'usersMiddle.inTrash' => false,
            'usersMiddle.folderId' => $folderId
        ];
        $this->filterOnlyMy($result);
    }

    protected function filterOnlyMy(&$result)
    {
        if (!$this->hasJoin('users', $result) && !$this->hasLeftJoin('users', $result)) {
            $this->addJoin('users', $result);
        }

        $result['whereClause'][] = [
            'usersMiddle.userId' => $this->getUser()->id
        ];
    }

    protected function boolFilterOnlyMy(&$result)
    {
        $this->addLeftJoin(['users', 'usersOnlyMyFilter'], $result);
        $this->setDistinct(true, $result);

        return [
            'usersOnlyMyFilterMiddle.userId' => $this->getUser()->id
        ];
    }

    protected function addUsersColumns(&$result)
    {
        $result['select'] = $result['select'] ?? [];

        if (!count($result['select'])) {
            $result['select'][] = '*';
        }

        $itemList = [
            'isRead',
            'isImportant',
            'inTrash',
            'folderId',
        ];

        foreach ($itemList as $item) {
            $result['select'][] = [
                'usersMiddle.' . $item,
                $item,
            ];
        }
    }

    protected function filterInbox(&$result)
    {
        $eaList = $this->getEntityManager()
            ->getRepository('User')
            ->getRelation($this->getUser(), 'emailAddresses')
            ->find();

        $idList = [];

        foreach ($eaList as $ea) {
            $idList[] = $ea->id;
        }

        $group = [
            'usersMiddle.inTrash=' => false,
            'usersMiddle.folderId' => null,
            [
                'status' => ['Archived', 'Sent'],
            ]
        ];

        if (!empty($idList)) {
            $group['fromEmailAddressId!='] = $idList;
            $group[] = [
                'OR' => [
                    'status' => 'Archived',
                    'createdById!=' => $this->getUser()->id,
                ]
            ];
        } else {
            $group[] = [
                'status' => 'Archived',
                'createdById!=' => $this->getUser()->id,
            ];
        }
        $result['whereClause'][] = $group;

        $this->filterOnlyMy($result);
    }

    protected function filterImportant(&$result)
    {
        $result['whereClause'][] = $this->getWherePartIsImportantIsTrue();
        $this->filterOnlyMy($result);
    }

    protected function filterSent(&$result)
    {
        $eaList = $this->getEntityManager()
            ->getRepository('User')
            ->getRelation($this->getUser(), 'emailAddresses')
            ->find();

        $idList = [];
        foreach ($eaList as $ea) {
            $idList[] = $ea->id;
        }

        $result['whereClause'][] = [
            'OR' => [
                'fromEmailAddressId=' => $idList,
                [
                    'status' => 'Sent',
                    'createdById' => $this->getUser()->id
                ]
            ],
            [
                'status!=' => 'Draft'
            ],
            'usersMiddle.inTrash=' => false
        ];
    }

    protected function filterTrash(&$result)
    {
        $result['whereClause'][] = [
            'usersMiddle.inTrash=' => true
        ];
        $this->filterOnlyMy($result);
    }

    protected function filterDrafts(&$result)
    {
        $result['whereClause'][] = [
            'status' => 'Draft',
            'createdById' => $this->getUser()->id
        ];
    }

    protected function filterArchived(&$result)
    {
        $result['whereClause'][] = [
            'status' => 'Archived'
        ];
    }

    protected function accessOnlyOwn(&$result)
    {
        $this->filterOnlyMy($result);
    }

    protected function accessPortalOnlyOwn(&$result)
    {
        $this->filterOnlyMy($result);
    }

    protected function accessOnlyTeam(&$result)
    {
        $this->setDistinct(true, $result);

        $this->addLeftJoin(['teams', 'teamsAccess'], $result);

        if (!$this->hasJoin('users', $result) && !$this->hasLeftJoin('users', $result)) {
            $this->addLeftJoin(['users', 'users'], $result);
        }

        $result['whereClause'][] = [
            'OR' => [
                'teamsAccessMiddle.teamId' => $this->getUser()->getLinkMultipleIdList('teams'),
                'usersMiddle.userId' => $this->getUser()->id,
            ]
        ];
    }

    protected function accessPortalOnlyAccount(&$result)
    {
        $this->setDistinct(true, $result);
        $this->addLeftJoin(['users', 'usersAccess'], $result);

        $orGroup = [
            'usersAccess.id' => $this->getUser()->id
        ];

        $accountIdList = $this->getUser()->getLinkMultipleIdList('accounts');
        if (count($accountIdList)) {
            $orGroup['accountId'] = $accountIdList;
        }

        $contactId = $this->getUser()->get('contactId');
        if ($contactId) {
            $orGroup[] = [
                'parentId' => $contactId,
                'parentType' => 'Contact'
            ];
        }

        $result['whereClause'][] = [
            'OR' => $orGroup
        ];
    }

    protected function accessPortalOnlyContact(&$result)
    {
        $this->setDistinct(true, $result);
        $this->addLeftJoin(['users', 'usersAccess'], $result);

        $orGroup = [
            'usersAccess.id' => $this->getUser()->id
        ];

        $contactId = $this->getUser()->get('contactId');
        if ($contactId) {
            $orGroup[] = [
                'parentId' => $contactId,
                'parentType' => 'Contact'
            ];
        }

        $result['whereClause'][] = [
            'OR' => $orGroup
        ];
    }

    protected function applyAdditionalToTextFilterGroup(string $textFilter, array &$group, array &$result)
    {
        if (
            strlen($textFilter) >= self::MIN_LENGTH_FOR_CONTENT_SEARCH
            &&
            strpos($textFilter, '@') !== false
            &&
            empty($result['hasFullTextSearch'])
        ) {
            $emailAddressId = $this->getEmailAddressIdByValue($textFilter);
            if ($emailAddressId) {
                $this->leftJoinEmailAddress($result);
                $group = [];
                $group['fromEmailAddressId'] = $emailAddressId;
                $group['emailEmailAddress.emailAddressId'] = $emailAddressId;
            } else {
                $group = [];
            }
        }
    }

    protected function getEmailAddressIdByValue($value)
    {
        $pdo = $this->getEntityManager()->getPDO();

        $emailAddress = $this->getEntityManager()->getRepository('EmailAddress')->where([
            'lower' => strtolower($value)
        ])->findOne();

        $emailAddressId = null;
        if ($emailAddress) {
            $emailAddressId = $emailAddress->id;
        }

        return $emailAddressId;
    }

    protected function leftJoinEmailAddress(&$result)
    {
        if ($this->hasLeftJoin('emailEmailAddress', $result)) return;

        $this->setDistinct(true, $result);

        $this->addLeftJoin([
            'EmailEmailAddress',
            'emailEmailAddress',
            [
                'emailId:' => 'id',
                'deleted' => false,
            ]
        ], $result);
    }

    protected function getWherePartEmailAddressEquals($value, array &$result)
    {
        if (!$value) {
            return ['id' => null];
        }

        $emailAddressId = $this->getEmailAddressIdByValue($value);

        if (!$emailAddressId) {
            return ['id' => null];
        }

        $this->setDistinct(true, $result);
        $alias = 'emailEmailAddress' . strval(rand(10000, 99999));

        $this->addLeftJoin([
            'EmailEmailAddress',
            $alias,
            [
                'emailId:' => 'id',
                'deleted' => false,
            ]
        ], $result);

        return [
            'OR' => [
                'fromEmailAddressId' => $emailAddressId,
                $alias . '.emailAddressId' => $emailAddressId,
            ],
        ];
    }

    protected function getWherePartFromEquals($value, array &$result)
    {
        if (!$value) {
            return ['id' => null];
        }

        $emailAddressId = $this->getEmailAddressIdByValue($value);

        if (!$emailAddressId) {
            return ['id' => null];
        }

        return [
            'fromEmailAddressId' => $emailAddressId,
        ];
    }

    protected function getWherePartToEquals($value, array &$result)
    {
        if (!$value) {
            return ['id' => null];
        }

        $emailAddressId = $this->getEmailAddressIdByValue($value);

        if (!$emailAddressId) {
            return ['id' => null];
        }

        $alias = 'emailEmailAddress' . strval(rand(10000, 99999));

        $this->addLeftJoin([
            'EmailEmailAddress',
            $alias,
            [
                'emailId:' => 'id',
                'deleted' => false,
            ]
        ], $result);

        return [
            $alias . '.emailAddressId' => $emailAddressId,
            $alias . '.addressType' => 'to',
        ];
    }

    protected function getWherePartIsNotRepliedIsTrue()
    {
        return [
            'isReplied' => false
        ];
    }

    protected function getWherePartIsNotRepliedIsFalse()
    {
        return [
            'isReplied' => true
        ];
    }

    public function getWherePartIsNotReadIsTrue()
    {
        return [
            'usersMiddle.isRead' => false,
            'OR' => [
                'sentById' => null,
                'sentById!=' => $this->getUser()->id
            ]
        ];
    }

    protected function getWherePartIsNotReadIsFalse()
    {
        return [
            'usersMiddle.isRead' => true
        ];
    }

    protected function getWherePartIsReadIsTrue()
    {
        return [
            'usersMiddle.isRead' => true
        ];
    }

    protected function getWherePartIsReadIsFalse()
    {
        return [
            'usersMiddle.isRead' => false,
            'OR' => [
                'sentById' => null,
                'sentById!=' => $this->getUser()->id
            ]
        ];
    }

    protected function getWherePartIsImportantIsTrue()
    {
        return [
            'usersMiddle.isImportant' => true
        ];
    }

    protected function getWherePartIsImportantIsFalse()
    {
        return [
            'usersMiddle.isImportant' => false
        ];
    }
}
