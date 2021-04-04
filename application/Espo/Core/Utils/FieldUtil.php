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

namespace Espo\Core\Utils;

class FieldUtil
{
    private $metadata;

    private $fieldByTypeListCache = [];

    public function __construct(Metadata $metadata)
    {
        $this->metadata = $metadata;
    }

    private function getAttributeListByType($scope, $name, $type)
    {
        $fieldType = $this->metadata->get('entityDefs.' . $scope . '.fields.' . $name . '.type');

        if (!$fieldType) {
            return [];
        }

        $defs = $this->metadata->get('fields.' . $fieldType);

        if (!$defs) {
            return [];
        }

        if (is_object($defs)) {
            $defs = get_object_vars($defs);
        }

        $fieldList = [];

        if (isset($defs[$type . 'Fields'])) {
            $list = $defs[$type . 'Fields'];
            $naming = 'suffix';
            if (isset($defs['naming'])) {
                $naming = $defs['naming'];
            }
            if ($naming == 'prefix') {
                foreach ($list as $f) {
                    if ($f === '') {
                        $fieldList[] = $name;
                    } else {
                        $fieldList[] = $f . ucfirst($name);
                    }
                }
            } else {
                foreach ($list as $f) {
                    $fieldList[] = $name . ucfirst($f);
                }
            }
        } else {
            if ($type == 'actual') {
                $fieldList[] = $name;
            }
        }

        return $fieldList;
    }

    public function getAdditionalActualAttributeList(string $scope, string $name) : array
    {
        $attributeList = [];

        $list = $this->metadata->get(['entityDefs', $scope, 'fields', $name, 'additionalAttributeList']);

        if (empty($list)) {
            return [];
        }

        $type = $this->metadata->get(['entityDefs', $scope, 'fields', $name, 'type']);

        if (!$type) {
            return [];
        }

        $naming = $this->metadata->get(['fields', $type, 'naming'], 'suffix');

        if ($naming == 'prefix') {
            foreach ($list as $f) {
                $attributeList[] = $f . ucfirst($name);
            }
        } else {
            foreach ($list as $f) {
                $attributeList[] = $name . ucfirst($f);
            }
        }

        return $attributeList;
    }

    public function getActualAttributeList(string $scope, string $name) : array
    {
        return array_merge(
            $this->getAttributeListByType($scope, $name, 'actual'), $this->getAdditionalActualAttributeList($scope, $name)
        );
    }

    public function getNotActualAttributeList(string $scope, string $name) : array
    {
        return $this->getAttributeListByType($scope, $name, 'notActual');
    }

    public function getAttributeList(string $scope, string $name) : array
    {
        return array_merge($this->getActualAttributeList($scope, $name), $this->getNotActualAttributeList($scope, $name));
    }

    public function getFieldByTypeList(string $scope, string $type) : array
    {
        if (!array_key_exists($scope, $this->fieldByTypeListCache)) {
            $this->fieldByTypeListCache[$scope] = [];
        }

        if (!array_key_exists($type, $this->fieldByTypeListCache[$scope])) {
            $fieldDefs = $this->metadata->get(['entityDefs', $scope, 'fields'], []);
            $list = [];
            foreach ($fieldDefs as $field => $defs) {
                if (isset($defs['type']) && $defs['type'] === $type) {
                    $list[] = $field;
                }
            }
            $this->fieldByTypeListCache[$scope][$type] = $list;
        }

        return $this->fieldByTypeListCache[$scope][$type];
    }

    private function getFieldTypeAttributeListByType($fieldType, $name, $type)
    {
        $defs = $this->metadata->get(['fields', $fieldType]);

        if (!$defs) {
            return [];
        }

        $attributeList = [];

        if (isset($defs[$type . 'Fields'])) {
            $list = $defs[$type . 'Fields'];
            $naming = 'suffix';
            if (isset($defs['naming'])) {
                $naming = $defs['naming'];
            }
            if ($naming == 'prefix') {
                foreach ($list as $f) {
                    $attributeList[] = $f . ucfirst($name);
                }
            } else {
                foreach ($list as $f) {
                    $attributeList[] = $name . ucfirst($f);
                }
            }
        } else {
            if ($type == 'actual') {
                $attributeList[] = $name;
            }
        }

        return $attributeList;
    }

    public function getFieldTypeAttributeList(string $fieldType, string $name) : array
    {
        return array_merge(
            $this->getFieldTypeAttributeListByType($fieldType, $name, 'actual'),
            $this->getFieldTypeAttributeListByType($fieldType, $name, 'notActual')
        );
    }

    public function getEntityTypeFieldList(string $entityType) : array
    {
        return array_keys($this->metadata->get(['entityDefs', $entityType, 'fields'], []));
    }

    public function getEntityTypeFieldParam(string $entityType, string $field, string $param)
    {
        return $this->metadata->get(['entityDefs', $entityType, 'fields', $field, $param]);
    }

    public function getEntityTypeAttributeList(string $entityType) : array
    {
        $attributeList = [];

        foreach ($this->getEntityTypeFieldList($entityType) as $field) {
            $attributeList = array_merge(
                $attributeList,
                $this->getAttributeList($entityType, $field)
            );
        }

        return $attributeList;
    }
}
