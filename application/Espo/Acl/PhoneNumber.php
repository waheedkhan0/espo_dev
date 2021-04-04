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

namespace Espo\Acl;

use Espo\Entities\User as EntityUser;
use Espo\ORM\Entity;

class PhoneNumber extends \Espo\Core\Acl\Acl
{
    public function checkEditInEntity(EntityUser $user, Entity $entity, Entity $excludeEntity)
    {
        $id = $entity->id;

        $isFobidden = false;

        $repository = $this->getEntityManager()->getRepository('PhoneNumber');

        if (!$user->isAdmin()) {
            $entityWithSameNumberList = $repository->getEntityListByPhoneNumberId($id, $excludeEntity);
            foreach ($entityWithSameNumberList as $e) {
                if (!$this->getAclManager()->check($user, $e, 'edit')) {
                    $isFobidden = true;
                    if (
                        $e->getEntityType() === 'User' &&
                        $e->isPortal() &&
                        $excludeEntity->getEntityType() === 'Contact' &&
                        $e->get('contactId') === $excludeEntity->id
                    ) {
                        $isFobidden = false;
                    }
                    if ($isFobidden) break;
                }
            }
        }

        return !$isFobidden;
    }
}
