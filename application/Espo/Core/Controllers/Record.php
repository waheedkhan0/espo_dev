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

namespace Espo\Core\Controllers;

use Espo\Core\Exceptions\{
    Error,
    Forbidden,
    NotFound,
    BadRequest,
    ForbiddenSilent,
};

use Espo\Core\{
    Utils\Util,
    Utils\ControllerUtil,
    Record\Collection as RecordCollection,
};

use StdClass;

class Record extends Base
{
    const MAX_SIZE_LIMIT = 200;

    public static $defaultAction = 'list';

    protected function getEntityManager()
    {
        return $this->getContainer()->get('entityManager');
    }

    protected function getRecordService(?string $name = null) : object
    {
        $name = $name ?? $this->name;

        return $this->getContainer()->get('recordServiceContainer')->get($name);
    }

    public function actionRead($params, $data, $request)
    {
        $id = $params['id'];
        $entity = $this->getRecordService()->read($id);

        if (!$entity) throw new NotFound();

        return $entity->getValueMap();
    }

    public function actionPatch($params, $data, $request)
    {
        return $this->actionUpdate($params, $data, $request);
    }

    public function actionCreate($params, $data, $request)
    {
        if (!is_object($data)) throw new BadRequest();

        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check($this->name, 'create')) {
            throw new Forbidden("No create access for {$this->name}.");
        }

        $service = $this->getRecordService();

        if ($entity = $service->create($data)) {
            return $entity->getValueMap();
        }

        throw new Error();
    }

    public function actionUpdate($params, $data, $request)
    {
        if (!is_object($data)) throw new BadRequest();

        if (!$request->isPut() && !$request->isPatch()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check($this->name, 'edit')) {
            throw new Forbidden("No edit access for {$this->name}.");
        }

        $id = $params['id'];

        if ($entity = $this->getRecordService()->update($id, $data)) {
            return $entity->getValueMap();
        }

        throw new Error();
    }

    public function actionList($params, $data, $request)
    {
        if (!$this->getAcl()->check($this->name, 'read')) {
            throw new Forbidden("No read access for {$this->name}.");
        }

        $params = [];
        $this->fetchListParamsFromRequest($params, $request, $data);

        $maxSizeLimit = $this->getConfig()->get('recordListMaxSizeLimit', self::MAX_SIZE_LIMIT);
        if (empty($params['maxSize'])) {
            $params['maxSize'] = $maxSizeLimit;
        }
        if (!empty($params['maxSize']) && $params['maxSize'] > $maxSizeLimit) {
            throw new Forbidden("Max size should should not exceed " . $maxSizeLimit . ". Use offset and limit.");
        }

        $result = $this->getRecordService()->find($params);

        if ($result instanceof RecordCollection) {
            return (object) [
                'total' => $result->getTotal(),
                'list' => $result->getValueMapList(),
            ];
        }

        if (is_array($result)) {
            return (object) [
                'total' => $result['total'],
                'list' => isset($result['collection']) ? $result['collection']->getValueMapList() : $result['list']
            ];
        }

        return (object) [
            'total' => $result->total,
            'list' => isset($result->collection) ? $result->collection->getValueMapList() : $result->list
        ];
    }

    public function getActionListKanban($params, $data, $request)
    {
        if (!$this->getAcl()->check($this->name, 'read')) {
            throw new Forbidden("No read access for {$this->name}.");
        }

        $params = [];

        $this->fetchListParamsFromRequest($params, $request, $data);

        $maxSizeLimit = $this->getConfig()->get('recordListMaxSizeLimit', self::MAX_SIZE_LIMIT);

        if (empty($params['maxSize'])) {
            $params['maxSize'] = $maxSizeLimit;
        }

        if (!empty($params['maxSize']) && $params['maxSize'] > $maxSizeLimit) {
            throw new Forbidden("Max size should should not exceed " . $maxSizeLimit . ". Use offset and limit.");
        }

        $result = $this->getRecordService()->getListKanban($params);

        return (object) [
            'total' => $result->getTotal(),
            'list' => $result->getCollection()->getValueMapList(),
            'additionalData' => $result->getData(),
        ];
    }

    protected function fetchListParamsFromRequest(&$params, $request, $data)
    {
        ControllerUtil::fetchListParamsFromRequest($params, $request, $data);
    }

    public function actionListLinked($params, $data, $request)
    {
        $id = $params['id'];
        $link = $params['link'];

        $params = [];
        $this->fetchListParamsFromRequest($params, $request, $data);

        $maxSizeLimit = $this->getConfig()->get('recordListMaxSizeLimit', self::MAX_SIZE_LIMIT);
        if (empty($params['maxSize'])) {
            $params['maxSize'] = $maxSizeLimit;
        }
        if (!empty($params['maxSize']) && $params['maxSize'] > $maxSizeLimit) {
            throw new Forbidden("Max size should should not exceed " . $maxSizeLimit . ". Use offset and limit.");
        }

        $result = $this->getRecordService()->findLinked($id, $link, $params);

        if ($result instanceof RecordCollection) {
            return (object) [
                'total' => $result->getTotal(),
                'list' => $result->getValueMapList(),
            ];
        }

        if (is_array($result)) {
            return [
                'total' => $result['total'],
                'list' => isset($result['collection']) ? $result['collection']->getValueMapList() : $result['list']
            ];
        }

        return (object) [
            'total' => $result->total,
            'list' => isset($result->collection) ? $result->collection->getValueMapList() : $result->list
        ];
    }

    public function actionDelete($params, $data, $request)
    {
        if (!$request->isDelete()) {
            throw new BadRequest();
        }

        $id = $params['id'];

        $this->getRecordService()->delete($id);

        return true;
    }

    public function actionExport($params, $data, $request)
    {
        if (!is_object($data)) throw new BadRequest();

        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if ($this->getConfig()->get('exportDisabled') && !$this->getUser()->isAdmin()) {
            throw new Forbidden();
        }

        if ($this->getAcl()->get('exportPermission') !== 'yes' && !$this->getUser()->isAdmin()) {
            throw new Forbidden();
        }

        if (!$this->getAcl()->check($this->name, 'read')) {
            throw new Forbidden();
        }

        $ids = isset($data->ids) ? $data->ids : null;
        $where = isset($data->where) ? json_decode(json_encode($data->where), true) : null;
        $byWhere = isset($data->byWhere) ? $data->byWhere : false;
        $selectData = isset($data->selectData) ? json_decode(json_encode($data->selectData), true) : null;

        $actionParams = [];
        if ($byWhere) {
            $actionParams['selectData'] = $selectData;
            $actionParams['where'] = $where;
        } else {
            $actionParams['ids'] = $ids;
        }

        if (isset($data->attributeList)) {
            $actionParams['attributeList'] = $data->attributeList;
        }

        if (isset($data->fieldList)) {
            $actionParams['fieldList'] = $data->fieldList;
        }

        if (isset($data->format)) {
            $actionParams['format'] = $data->format;
        }

        return [
            'id' => $this->getRecordService()->export($actionParams),
        ];
    }

    public function actionMassUpdate($params, $data, $request)
    {
        if (!$request->isPut()) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check($this->name, 'edit')) {
            throw new Forbidden("No edit access for {$this->name}.");
        }
        if (empty($data->attributes)) {
            throw new BadRequest();
        }

        if ($this->getAcl()->get('massUpdatePermission') !== 'yes') {
            throw new Forbidden("No massUpdatePermission.");
        }

        $actionParams = $this->getMassActionParamsFromData($data);

        $attributes = $data->attributes;

        return $this->getRecordService()->massUpdate($actionParams, $attributes);
    }

    public function postActionMassDelete($params, $data, $request)
    {
        if (!$this->getAcl()->check($this->name, 'delete')) {
            throw new Forbidden();
        }

        $actionParams = $this->getMassActionParamsFromData($data);

        if (array_key_exists('where', $actionParams)) {
            if ($this->getAcl()->get('massUpdatePermission') !== 'yes') {
                throw new Forbidden("No massUpdatePermission.");
            }
        }

        return $this->getRecordService()->massDelete($actionParams);
    }

    public function actionCreateLink($params, $data, $request)
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (empty($params['id']) || empty($params['link'])) {
            throw new BadRequest();
        }

        $id = $params['id'];
        $link = $params['link'];

        if (!empty($data->massRelate)) {
            if (!is_array($data->where)) {
                throw new BadRequest();
            }
            $where = json_decode(json_encode($data->where), true);

            $selectData = null;
            if (isset($data->selectData) && is_array($data->selectData)) {
                $selectData = json_decode(json_encode($data->selectData), true);
            }

            return $this->getRecordService()->massLink($id, $link, $where, $selectData);
        } else {
            $foreignIdList = [];
            if (isset($data->id)) {
                $foreignIdList[] = $data->id;
            }
            if (isset($data->ids) && is_array($data->ids)) {
                foreach ($data->ids as $foreignId) {
                    $foreignIdList[] = $foreignId;
                }
            }

            $result = false;
            foreach ($foreignIdList as $foreignId) {
                $this->getRecordService()->link($id, $link, $foreignId);
                $result = true;
            }
            return $result;
        }

        throw new Error();
    }

    public function actionRemoveLink($params, $data, $request)
    {
        if (!$request->isDelete()) {
            throw new BadRequest();
        }

        $id = $params['id'];
        $link = $params['link'];

        if (empty($params['id']) || empty($params['link'])) {
            throw new BadRequest();
        }

        $foreignIdList = [];
        if (isset($data->id)) {
            $foreignIdList[] = $data->id;
        }
        if (isset($data->ids) && is_array($data->ids)) {
            foreach ($data->ids as $foreignId) {
                $foreignIdList[] = $foreignId;
            }
        }

        $result = false;
        foreach ($foreignIdList as $foreignId) {
            $this->getRecordService()->unlink($id, $link, $foreignId);
            $result = true;
        }
        return $result;
    }

    public function actionFollow($params, $data, $request)
    {
        if (!$request->isPut()) {
            throw new BadRequest();
        }
        if (!$this->getAcl()->check($this->name, 'stream')) {
            throw new Forbidden("No stream access for {$this->name}.");
        }
        $id = $params['id'];
        return $this->getRecordService()->follow($id);
    }

    public function actionUnfollow($params, $data, $request)
    {
        if (!$request->isDelete()) {
            throw new BadRequest();
        }
        if (!$this->getAcl()->check($this->name, 'read')) {
            throw new Forbidden("No read access for {$this->name}.");
        }
        $id = $params['id'];
        return $this->getRecordService()->unfollow($id);
    }

    public function actionMerge($params, $data, $request)
    {
        if (!$request->isPost()) {
            throw new BadRequest();
        }

        if (
            empty($data->targetId) ||
            empty($data->sourceIds) ||
            !is_array($data->sourceIds) ||
            !($data->attributes instanceof StdClass)
        ) {
            throw new BadRequest();
        }

        $targetId = $data->targetId;
        $sourceIds = $data->sourceIds;
        $attributes = $data->attributes;

        if (!$this->getAcl()->check($this->name, 'edit')) {
            throw new Forbidden("No edit access for {$this->name}.");
        }

        return $this->getRecordService()->merge($targetId, $sourceIds, $attributes);
    }

    public function postActionGetDuplicateAttributes($params, $data, $request)
    {
        if (empty($data->id)) {
            throw new BadRequest();
        }

        if (!$this->getAcl()->check($this->name, 'create')) {
            throw new Forbidden();
        }
        if (!$this->getAcl()->check($this->name, 'read')) {
            throw new Forbidden();
        }

        return $this->getRecordService()->getDuplicateAttributes($data->id);
    }

    public function postActionMassFollow($params, $data, $request)
    {
        if (!$this->getAcl()->check($this->name, 'stream')) {
            throw new Forbidden();
        }

        $actionParams = $this->getMassActionParamsFromData($data);

        return $this->getRecordService()->massFollow($actionParams);
    }

    public function postActionMassUnfollow($params, $data, $request)
    {
        if (!$this->getAcl()->check($this->name, 'stream')) {
            throw new Forbidden("No stream access for {$this->name}.");
        }

        $actionParams = $this->getMassActionParamsFromData($data);

        return $this->getRecordService()->massUnfollow($actionParams);
    }

    protected function getMassActionParamsFromData($data)
    {
        $params = [];
        if (property_exists($data, 'where') && !empty($data->byWhere)) {
            $where = json_decode(json_encode($data->where), true);
            $params['where'] = $where;
            if (property_exists($data, 'selectData')) {
                $params['selectData'] = json_decode(json_encode($data->selectData), true);
            }
        } else if (property_exists($data, 'ids')) {
            $params['ids'] = $data->ids;
        }

        return $params;
    }

    public function postActionMassRecalculateFormula($params, $data, $request)
    {
        if (!$this->getUser()->isAdmin()) throw new Forbidden();
        if (!$this->getAcl()->check($this->name, 'edit')) throw new Forbidden();

        return $this->getRecordService()->massRecalculateFormula($this->getMassActionParamsFromData($data));
    }

    public function postActionRestoreDeleted($params, $data, $request)
    {
        if (!$this->getUser()->isAdmin()) throw new Forbidden();

        $id = $data->id ?? null;
        if (!$id) throw new Forbidden();

        return $this->getRecordService()->restoreDeleted($id);
    }

    public function postActionMassConvertCurrency($params, $data, $request)
    {
        if (!$this->getAcl()->checkScope($this->name, 'edit')) throw new Forbidden();
        if ($this->getAcl()->get('massUpdatePermission') !== 'yes') throw new Forbidden();

        $actionParams = $this->getMassActionParamsFromData($data);

        $fieldList = $data->fieldList ?? null;
        if (!empty($data->field)) {
            if (!is_array($fieldList)) $fieldList = [];
            $fieldList[] = $data->field;
        }

        if (empty($data->currencyRates)) throw new BadRequest();
        if (empty($data->targetCurrency)) throw new BadRequest();
        if (empty($data->baseCurrency)) throw new BadRequest();

        return $this->getRecordService()->massConvertCurrency(
            $actionParams, $data->targetCurrency, $data->baseCurrency, $data->currencyRates, $fieldList
        );
    }

    public function postActionConvertCurrency($params, $data, $request)
    {
        if (!$this->getAcl()->checkScope($this->name, 'edit')) throw new Forbidden();

        $fieldList = $data->fieldList ?? null;
        if (!empty($data->field)) {
            if (!is_array($fieldList)) $fieldList = [];
            $fieldList[] = $data->field;
        }

        if (empty($data->id)) throw new BadRequest();
        if (empty($data->currencyRates)) throw new BadRequest();
        if (empty($data->targetCurrency)) throw new BadRequest();
        if (empty($data->baseCurrency)) throw new BadRequest();

        return $this->getRecordService()->convertCurrency(
            $data->id, $data->targetCurrency, $data->baseCurrency, $data->currencyRates, $fieldList
        );
    }
}
