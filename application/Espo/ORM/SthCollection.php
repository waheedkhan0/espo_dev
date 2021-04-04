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

namespace Espo\ORM;

use Espo\ORM\{
    QueryParams\Select as SelectQuery,
    QueryComposer\QueryComposer as QueryComposer,
};

use IteratorAggregate;
use Countable;
use PDO;

/**
 * Reasonable to use when selecting a large number of records.
 * It doesn't allocate a memory for every entity.
 * Entities are fetched on each iteration while traversing a collection.
 *
 * STH stands for Statement Handle.
 */
class SthCollection implements Collection, IteratorAggregate, Countable
{
    protected $entityManager;

    protected $entityType;

    protected $query = null;

    private $sth = null;

    private $sql = null;

    protected $entityList = [];

    protected function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    protected function getQueryComposer() : QueryComposer
    {
        return $this->entityManager->getQueryComposer();
    }

    protected function getEntityFactory() : EntityFactory
    {
        return $this->entityManager->getEntityFactory();
    }

    protected function setSql(string $sql)
    {
        $this->sql = $sql;
    }

    protected function getPDO() : PDO
    {
        return $this->entityManager->getPDO();
    }

    protected function executeQuery()
    {
        $sql = $this->getSql();

        $sth = $this->getPDO()->prepare($sql);

        $sth->execute();

        $this->sth = $sth;
    }

    protected function getSql() : string
    {
        if (!$this->sql) {
            $this->sql = $this->getQueryComposer()->compose($this->getQuery());
        }

        return $this->sql;
    }

    protected function getQuery() : SelectQuery
    {
        return $this->query;
    }

    public function getIterator()
    {
        return (function () {
            if (isset($this->sth)) {
                $this->sth->execute();
            }

            while ($row = $this->fetchRow()) {
                $entity = $this->getEntityFactory()->create($this->entityType);

                $entity->set($row);
                $entity->setAsFetched();

                $this->prepareEntity($entity);

                $this->entityList[] = $entity;

                yield $entity;
            }
        })();
    }

    protected function executeQueryIfNotExecuted()
    {
        if (!$this->sth) {
            $this->executeQuery();
        }
    }

    protected function fetchRow()
    {
        $this->executeQueryIfNotExecuted();

        return $this->sth->fetch(\PDO::FETCH_ASSOC);
    }

    public function count() : int
    {
        $this->executeQueryIfNotExecuted();

        $rowCount = $this->sth->rowCount();

        // MySQL may not return a row count for select queries.
        if ($rowCount) {
            return $rowCount;
        }

        return count($this->getValueMapList());
    }

    protected function prepareEntity(Entity $entity)
    {
    }

    /**
     * @deprecated
     */
    public function toArray(bool $itemsAsObjects = false) : array
    {
        $arr = [];
        foreach ($this as $entity) {
            if ($itemsAsObjects) {
                $item = $entity->getValueMap();
            } else {
                $item = $entity->toArray();
            }
            $arr[] = $item;
        }
        return $arr;
    }

    public function getValueMapList() : array
    {
        return $this->toArray(true);
    }


    /**
     * Whether Is fetched from DB. SthCollection is always fetched.
     */
    public function isFetched() : bool
    {
        return true;
    }

    /**
     * Get an entity type.
     */
    public function getEntityType() : string
    {
        return $this->entityType;
    }

    public static function fromQuery(SelectQuery $query, EntityManager $entityManager) : self
    {
        $obj = new self($entityManager);

        $obj->entityType = $query->getFrom();
        $obj->query = $query;

        return $obj;
    }

    public static function fromSql(string $entityType, string $sql, EntityManager $entityManager) : self
    {
        $obj = new self($entityManager);

        $obj->entityType = $entityType;
        $obj->sql = $sql;

        return $obj;
    }
}
