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

namespace Espo\Repositories;

use Espo\ORM\Entity;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Conflict;

use Espo\Core\Utils\ApiKey;

use Espo\Core\Di;

class User extends \Espo\Core\Repositories\Database implements

    Di\ConfigAware
{
    use Di\ConfigSetter;

    protected function beforeSave(Entity $entity, array $options = [])
    {
        if ($entity->has('type') && !$entity->get('type')) {
            $entity->set('type', 'regular');
        }

        if ($entity->isApi()) {
            if ($entity->isAttributeChanged('userName')) {
                $entity->set('lastName', $entity->get('userName'));
            }
            if ($entity->has('authMethod') && $entity->get('authMethod') !== 'Hmac') {
                $entity->clear('secretKey');
            }
        } else {
            if ($entity->isAttributeChanged('type')) {
                $entity->set('authMethod', null);
            }
        }

        parent::beforeSave($entity, $options);

        if ($entity->has('type') && !$entity->isPortal()) {
            $entity->set('portalRolesIds', []);
            $entity->set('portalRolesNames', (object)[]);
            $entity->set('portalsIds', []);
            $entity->set('portalsNames', (object)[]);
        }

        if ($entity->has('type') && $entity->isPortal()) {
            $entity->set('rolesIds', []);
            $entity->set('rolesNames', (object)[]);
            $entity->set('teamsIds', []);
            $entity->set('teamsNames', (object)[]);
            $entity->set('defaultTeamId', null);
            $entity->set('defaultTeamName', null);
        }

        if ($entity->isNew()) {
            $userName = $entity->get('userName');
            if (empty($userName)) {
                throw new Error("Username can't be empty.");
            }

            $this->getEntityManager()->getLocker()->lockExclusive($this->entityType);

            $user = $this
                ->select(['id'])
                ->where([
                    'userName' => $userName,
                ])
                ->findOne();

            if ($user) {
                $this->getEntityManager()->getLocker()->rollback();

                throw new Conflict(json_encode(['reason' => 'userNameExists']));
            }
        } else {
            if ($entity->isAttributeChanged('userName')) {
                $userName = $entity->get('userName');
                if (empty($userName)) {
                    throw new Error("Username can't be empty.");
                }

                $this->getEntityManager()->getLocker()->lockExclusive($this->entityType);

                $user = $this
                    ->select(['id'])
                    ->where([
                        'userName' => $userName,
                        'id!=' => $entity->id,
                    ])
                    ->findOne();

                if ($user) {
                    $this->getEntityManager()->getLocker()->rollback();

                    throw new Conflict(json_encode(['reason' => 'userNameExists']));
                }
            }
        }
    }

    protected function afterSave(Entity $entity, array $options = [])
    {
        if ($this->getEntityManager()->getLocker()->isLocked()) {
            $this->getEntityManager()->getLocker()->commit();
        }

        parent::afterSave($entity, $options);

        if ($entity->isApi()) {
            if (
                $entity->get('apiKey') && $entity->get('secretKey') &&
                (
                    $entity->isAttributeChanged('apiKey') || $entity->isAttributeChanged('authMethod')
                )
            ) {
                $apiKeyUtil = new ApiKey($this->config);
                $apiKeyUtil->storeSecretKeyForUserId($entity->id, $entity->get('secretKey'));
            }

            if ($entity->isAttributeChanged('authMethod') && $entity->get('authMethod') !== 'Hmac') {
                $apiKeyUtil = new ApiKey($this->config);
                $apiKeyUtil->removeSecretKeyForUserId($entity->id);
            }
        }
    }

    protected function afterRemove(Entity $entity, array $options = [])
    {
        parent::afterRemove($entity, $options);

        if ($entity->isApi() && $entity->get('authMethod') === 'Hmac') {
            $apiKeyUtil = new ApiKey($this->config);
            $apiKeyUtil->removeSecretKeyForUserId($entity->id);
        }

        $userData = $this->getEntityManager()->getRepository('UserData')->getByUserId($entity->id);
        if ($userData) {
            $this->getEntityManager()->removeEntity($userData);
        }
    }

    public function checkBelongsToAnyOfTeams(string $userId, array $teamIds) : bool
    {
        if (empty($teamIds)) {
            return false;
        }

        return (bool) $this->getEntityManager()->getRepository('TeamUser')
            ->where([
                'deleted' => false,
                'userId' => $userId,
                'teamId' => $teamIds,
            ])
            ->findOne();
    }
}
