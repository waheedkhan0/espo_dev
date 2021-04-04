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

use InvalidArgumentException;

/**
 * Metadata.
 */
class Metadata
{
    protected $data;

    protected $dataProvider;

    public function __construct(MetadataDataProvider $dataProvider)
    {
        $this->data = $dataProvider->get();

        $this->dataProvider = $dataProvider;
    }

    /**
     * Update data from the data provider.
     */
    public function updateData()
    {
        $this->data = $this->dataProvider->get();
    }

    /**
     * Get a parameter or parameters by key. Key can be a string or array path.
     */
    public function get(string $entityType, $key = null, $default = null)
    {
        if (!$this->has($entityType)) {
            return null;
        }

        $data = $this->data[$entityType];

        if ($key === null) {
            return $data;
        }

        return self::getValueByKey($data, $key, $default);
    }

    /**
     * Whether an entity type is available.
     */
    public function has(string $entityType) : bool
    {
        return array_key_exists($entityType, $this->data);
    }

    private static function getValueByKey(array $data, $key = null, $default = null)
    {
        if (!is_string($key) && !is_array($key) && !is_null($key)) {
            throw new InvalidArgumentException();
        }

        if (is_null($key) || empty($key)) {
            return $data;
        }

        if (!is_string($key) && !is_array($key)) {
            throw new InvalidArgumentException();
        }

        $path = $key;

        if (is_string($key)) {
            $path = explode('.', $key);
        }

        $item = $data;

        foreach ($path as $k) {
            if (!array_key_exists($k, $item)) {
                return $default;
            }

            $item = $item[$k];
        }

        return $item;
    }
}
