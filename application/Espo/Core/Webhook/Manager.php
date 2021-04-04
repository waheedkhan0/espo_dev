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

namespace Espo\Core\Webhook;

use Espo\Core\{
    Utils\Config,
    Utils\DataCache,
    Utils\FieldUtil,
    ORM\EntityManager,
    ORM\Entity,
};

/**
 * Processes events. Holds an information about existing events.
 */
class Manager
{
    private $cacheKey = 'webhooks';

    protected $skipAttributeList = ['isFollowed', 'modifiedAt', 'modifiedBy'];

    private $data = null;

    protected $config;
    protected $dataCache;
    protected $entityManager;
    protected $fieldUtil;

    public function __construct(
        Config $config,
        DataCache $dataCache,
        EntityManager $entityManager,
        FieldUtil $fieldUtil
    ) {
        $this->config = $config;
        $this->dataCache = $dataCache;
        $this->entityManager = $entityManager;
        $this->fieldUtil = $fieldUtil;

        $this->loadData();
    }

    private function loadData()
    {
        if ($this->config->get('useCache')) {
            if ($this->dataCache->has($this->cacheKey)) {
                $this->data = $this->dataCache->get($this->cacheKey);
            }
        }

        if (is_null($this->data)) {
            $this->data = $this->buildData();
        }

        if ($this->config->get('useCache')) {
            $this->storeDataToCache();
        }
    }

    private function storeDataToCache()
    {
        $this->dataCache->store($this->cacheKey, $this->data);
    }

    private function buildData()
    {
        $data = [];

        $list = $this->entityManager->getRepository('Webhook')
            ->select(['event'])
            ->groupBy(['event'])
            ->where([
                'isActive' => true,
                'event!=' => null,
            ])
            ->find();

        foreach ($list as $e) {
            $data[$e->get('event')] = true;
        }

        return $data;
    }

    /**
     * Add an event. To cache the information that at least one webhook for this event exists.
     */
    public function addEvent(string $event)
    {
        $this->data[$event] = true;

        if ($this->config->get('useCache')) {
            $this->storeDataToCache();
        }
    }

    /**
     * Remove an event. If no webhooks with this event left, then it will be removed from the cache.
     */
    public function removeEvent(string $event)
    {
        $notExists = !$this->entityManager
            ->getRepository('Webhook')
            ->select(['id'])
            ->where([
                'event' => $event,
                'isActive' => true,
            ])
            ->findOne();

        if ($notExists) {
            unset($this->data[$event]);

            if ($this->config->get('useCache')) {
                $this->storeDataToCache();
            }
        }
    }

    protected function eventExists(string $event) : bool
    {
        return isset($this->data[$event]);
    }

    protected function logDebugEvent(string $event, Entity $entity)
    {
        $GLOBALS['log']->debug("Webhook: {$event} on record {$entity->id}.");
    }

    /**
     * Process 'create' event.
     */
    public function processCreate(Entity $entity)
    {
        $event = $entity->getEntityType() . '.create';

        if (!$this->eventExists($event)) {
            return;
        }

        $this->entityManager->createEntity('WebhookEventQueueItem', [
            'event' => $event,
            'targetType' => $entity->getEntityType(),
            'targetId' => $entity->id,
            'data' => $entity->getValueMap(),
        ]);

        $this->logDebugEvent($event, $entity);
    }

    /**
     * Process 'delete' event.
     */
    public function processDelete(Entity $entity)
    {
        $event = $entity->getEntityType() . '.delete';

        if (!$this->eventExists($event)) {
            return;
        }

        $this->entityManager->createEntity('WebhookEventQueueItem', [
            'event' => $event,
            'targetType' => $entity->getEntityType(),
            'targetId' => $entity->id,
            'data' => (object) [
                'id' => $entity->id,
            ],
        ]);

        $this->logDebugEvent($event, $entity);
    }

    /**
     * Process 'update' event.
     */
    public function processUpdate(Entity $entity)
    {
        $event = $entity->getEntityType() . '.update';

        $data = (object) [];

        foreach ($entity->getAttributeList() as $attribute) {
            if (in_array($attribute, $this->skipAttributeList)) {
                continue;
            }

            if ($entity->isAttributeChanged($attribute)) {
                $data->$attribute = $entity->get($attribute);
            }
        }

        if (!count(get_object_vars($data))) {
            return;
        }

        $data->id = $entity->id;

        if ($this->eventExists($event)) {
            $this->entityManager->createEntity('WebhookEventQueueItem', [
                'event' => $event,
                'targetType' => $entity->getEntityType(),
                'targetId' => $entity->id,
                'data' => $data,
            ]);
            $this->logDebugEvent($event, $entity);
        }

        foreach ($this->fieldUtil->getEntityTypeFieldList($entity->getEntityType()) as $field) {
            $itemEvent = $entity->getEntityType() . '.fieldUpdate.' . $field;

            if (!$this->eventExists($itemEvent)) {
                continue;
            }

            $attributeList = $this->fieldUtil->getActualAttributeList($entity->getEntityType(), $field);
            $isChanged = false;

            foreach ($attributeList as $attribute) {
                if (in_array($attribute, $this->skipAttributeList)) {
                    continue;
                }

                if (property_exists($data, $attribute)) {
                    $isChanged = true;

                    break;
                }
            }

            if ($isChanged) {
                $itemData = (object) [];
                $itemData->id = $entity->id;
                $attributeList = $this->fieldUtil->getAttributeList($entity->getEntityType(), $field);

                foreach ($attributeList as $attribute) {
                    if (in_array($attribute, $this->skipAttributeList)) {
                        continue;
                    }

                    $itemData->$attribute = $entity->get($attribute);
                }

                $this->entityManager->createEntity('WebhookEventQueueItem', [
                    'event' => $itemEvent,
                    'targetType' => $entity->getEntityType(),
                    'targetId' => $entity->id,
                    'data' => $itemData,
                ]);

                $this->logDebugEvent($itemEvent, $entity);
            }
        }
    }
}
