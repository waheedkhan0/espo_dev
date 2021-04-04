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

use Espo\Core\Di;

class Portal extends \Espo\Core\Repositories\Database implements
    Di\ConfigAware
{
    use Di\ConfigSetter;

    public function loadUrlField(Entity $entity)
    {
        if ($entity->get('customUrl')) {
            $entity->set('url', $entity->get('customUrl'));
        }

        $siteUrl = $this->config->get('siteUrl');
        $siteUrl = rtrim($siteUrl , '/') . '/';
        $url = $siteUrl . 'portal/';

        if ($entity->id === $this->config->get('defaultPortalId')) {
            $entity->set('isDefault', true);
            $entity->setFetched('isDefault', true);
        } else {
            if ($entity->get('customId')) {
                $url .= $entity->get('customId') . '/';
            } else {
                $url .= $entity->id . '/';
            }
            $entity->set('isDefault', false);
            $entity->setFetched('isDefault', false);
        }

        if (!$entity->get('customUrl')) {
            $entity->set('url', $url);
        }
    }

    protected function afterSave(Entity $entity, array $options = [])
    {
        parent::afterSave($entity, $options);

        if ($entity->has('isDefault')) {
            if ($entity->get('isDefault')) {
                $defaultPortalId = $this->config->get('defaultPortalId');
                if ($defaultPortalId !== $entity->id) {
                    $this->config->set('defaultPortalId', $entity->id);
                    $this->config->save();
                }
            } else {
                if ($entity->isAttributeChanged('isDefault')) {
                    if ($entity->getFetched('isDefault')) {
                        $this->config->set('defaultPortalId', null);
                        $this->config->save();
                    }
                }
            }
        }
    }
}
