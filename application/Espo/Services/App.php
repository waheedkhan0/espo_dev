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

use Espo\Core\Exceptions\{
    Forbidden,
    Error,
    NotFound,
};

use Espo\Core\{
    Acl,
    AclManager,
    Select\SelectManagerFactory,
    DataManager,
    InjectableFactory,
    ServiceFactory,
    Utils\Metadata,
    Utils\Config,
    Utils\Util,
    Utils\Language,
    Utils\FieldUtil,
};

use Espo\Entities\User;
use Espo\Entities\Preferences;

use Espo\ORM\{
    EntityManager,
    Repository\RDBRepository,
    Entity,
};

use StdClass;
use Throwable;

class App
{
    protected $config;
    protected $entityManager;
    protected $metadata;
    protected $acl;
    protected $aclManager;
    protected $dataManager;
    protected $selectManagerFactory;
    protected $injectableFactory;
    protected $serviceFactory;
    protected $user;
    protected $preferences;
    protected $fieldUtil;

    public function __construct(
        Config $config,
        EntityManager $entityManager,
        Metadata $metadata,
        Acl $acl,
        AclManager $aclManager,
        DataManager $dataManager,
        SelectManagerFactory $selectManagerFactory,
        InjectableFactory $injectableFactory,
        ServiceFactory $serviceFactory,
        User $user,
        Preferences $preferences,
        FieldUtil $fieldUtil
    ) {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->metadata = $metadata;
        $this->acl = $acl;
        $this->aclManager = $aclManager;
        $this->dataManager = $dataManager;
        $this->selectManagerFactory = $selectManagerFactory;
        $this->injectableFactory = $injectableFactory;
        $this->serviceFactory = $serviceFactory;
        $this->user = $user;
        $this->preferences = $preferences;
        $this->fieldUtil = $fieldUtil;
    }

    public function getUserData()
    {
        $preferencesData = $this->preferences->getValueMap();

        $this->filterPreferencesData($preferencesData);

        $settingsService = $this->serviceFactory->create('Settings');

        $user = $this->user;

        if (!$user->has('teamsIds')) {
            $user->loadLinkMultipleField('teams');
        }
        if ($user->isPortal()) {
            $user->loadAccountField();
            $user->loadLinkMultipleField('accounts');
        }

        $settings = $this->serviceFactory->create('Settings')->getConfigData();

        if ($user->get('dashboardTemplateId')) {
            $dashboardTemplate = $this->entityManager->getEntity('DashboardTemplate', $user->get('dashboardTemplateId'));

            if ($dashboardTemplate) {
                $settings->forcedDashletsOptions = $dashboardTemplate->get('dashletsOptions') ?? (object) [];
                $settings->forcedDashboardLayout = $dashboardTemplate->get('layout') ?? [];
            }
        }

        $language = Language::detectLanguage($this->config, $this->preferences);

        $auth2FARequired = false;

        if (
            $user->isRegular() && $this->config->get('auth2FA') && $this->config->get('auth2FAForced') &&
            !$user->get('auth2FA')
        ) {
            $auth2FARequired = true;
        }

        $appParams = [
            'maxUploadSize' => $this->getMaxUploadSize() / 1024.0 / 1024.0,
            'isRestrictedMode' => $this->config->get('restrictedMode'),
            'passwordChangeForNonAdminDisabled' => $this->config->get('authenticationMethod', 'Espo') !== 'Espo',
            'timeZoneList' => $this->metadata->get(['entityDefs', 'Settings', 'fields', 'timeZone', 'options'], []),
            'auth2FARequired' => $auth2FARequired,
        ];

        foreach (($this->metadata->get(['app', 'appParams']) ?? []) as $paramKey => $item) {
            $className = $item['className'] ?? null;

            if (!$className) {
                continue;
            }

            try {
                $itemParams = $this->injectableFactory->create($className)->get();
            } catch (Throwable $e) {
                $GLOBALS['log']->error("appParam {$paramKey}: " . $e->getMessage());

                continue;
            }
            $appParams[$paramKey] = $itemParams;
        }

        return [
            'user' => $this->getUserDataForFrontend(),
            'acl' => $this->getAclDataForFrontend(),
            'preferences' => $preferencesData,
            'token' => $this->user->get('token'),
            'settings' => $settings,
            'language' => $language,
            'appParams' => $appParams,
        ];
    }

    protected function getUserDataForFrontend()
    {
        $user = $this->user;

        $emailAddressData = $this->getEmailAddressData();

        $data = $user->getValueMap();

        $data->emailAddressList = $emailAddressData->emailAddressList;
        $data->userEmailAddressList = $emailAddressData->userEmailAddressList;

        unset($data->authTokenId);
        unset($data->password);

        $forbiddenAttributeList = $this->acl->getScopeForbiddenAttributeList('User');

        $isPortal = $user->isPortal();

        foreach ($forbiddenAttributeList as $attribute) {
            if ($attribute === 'type') continue;
            if ($isPortal) {
                if (in_array($attribute, ['contactId', 'contactName', 'accountId', 'accountsIds'])) continue;
            } else {
                if (in_array($attribute, ['teamsIds', 'defaultTeamId', 'defaultTeamName'])) continue;
            }
            unset($data->$attribute);
        }

        return $data;
    }

    protected function getAclDataForFrontend()
    {
        $data = $this->acl->getMap();

        if (!$this->user->isAdmin()) {
            $data = unserialize(serialize($data));

            $scopeList = array_keys($this->metadata->get(['scopes'], []));
            foreach ($scopeList as $scope) {
                if (!$this->acl->check($scope)) {
                    unset($data->table->$scope);
                    unset($data->fieldTable->$scope);
                    unset($data->fieldTableQuickAccess->$scope);
                }
            }
        }

        return $data;
    }

    protected function getEmailAddressData()
    {
        $user = $this->user;

        $emailAddressList = [];
        $userEmailAddressList = [];

        $emailAddressCollection = $this->entityManager
            ->getRepository('User')
            ->getRelation($user, 'emailAddresses')
            ->find();

        foreach ($emailAddressCollection as $emailAddress) {
            if ($emailAddress->get('invalid')) {
                continue;
            }

            $userEmailAddressList[] = $emailAddress->get('name');

            if ($user->get('emailAddress') === $emailAddress->get('name')) {
                continue;
            }

            $emailAddressList[] = $emailAddress->get('name');
        }

        if ($user->get('emailAddress')) {
            array_unshift($emailAddressList, $user->get('emailAddress'));
        }

        $entityManager = $this->entityManager;

        $teamIdList = $user->getLinkMultipleIdList('teams');

        $groupEmailAccountPermission = $this->acl->get('groupEmailAccountPermission');

        if ($groupEmailAccountPermission && $groupEmailAccountPermission !== 'no') {
            if ($groupEmailAccountPermission === 'team') {
                if (count($teamIdList)) {
                    $inboundEmailList = $entityManager->getRepository('InboundEmail')
                        ->where([
                            'status' => 'Active',
                            'useSmtp' => true,
                            'smtpIsShared' => true,
                            'teamsMiddle.teamId' => $teamIdList,
                        ])
                        ->join('teams')
                        ->distinct()
                        ->find();

                    foreach ($inboundEmailList as $inboundEmail) {
                        if (!$inboundEmail->get('emailAddress')) continue;

                        $emailAddressList[] = $inboundEmail->get('emailAddress');
                    }
                }
            } else if ($groupEmailAccountPermission === 'all') {
                $inboundEmailList = $entityManager->getRepository('InboundEmail')
                    ->where([
                        'status' => 'Active',
                        'useSmtp' => true,
                        'smtpIsShared' => true,
                    ])
                    ->find();

                foreach ($inboundEmailList as $inboundEmail) {
                    if (!$inboundEmail->get('emailAddress')) continue;

                    $emailAddressList[] = $inboundEmail->get('emailAddress');
                }
            }
        }

        return (object) [
            'emailAddressList' => $emailAddressList,
            'userEmailAddressList' => $userEmailAddressList,
        ];
    }

    private function getMaxUploadSize()
    {
        $maxSize = 0;

        $postMaxSize = $this->convertPHPSizeToBytes(ini_get('post_max_size'));

        if ($postMaxSize > 0) {
            $maxSize = $postMaxSize;
        }

        $attachmentUploadMaxSize = $this->config->get('attachmentUploadMaxSize');

        if ($attachmentUploadMaxSize && (!$maxSize || $attachmentUploadMaxSize < $maxSize)) {
            $maxSize = $attachmentUploadMaxSize;
        }

        return $maxSize;
    }

    private function convertPHPSizeToBytes($size)
    {
        if (is_numeric($size)) return $size;

        $suffix = substr($size, -1);
        $value = substr($size, 0, -1);

        switch(strtoupper($suffix)) {
            case 'P':
                $value *= 1024;
            case 'T':
                $value *= 1024;
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
                break;
            }

        return $value;
    }

    protected function getTemplateEntityTypeList()
    {
        if (!$this->acl->checkScope('Template')) {
            return [];
        }

        $list = [];

        $selectManager = $this->selectManagerFactory->create('Template');

        $selectParams = $selectManager->getEmptySelectParams();
        $selectManager->applyAccess($selectParams);

        $templateList = $this->entityManager->getRepository('Template')
            ->select(['entityType'])
            ->groupBy(['entityType'])
            ->find($selectParams);

        foreach ($templateList as $template) {
            $list[] = $template->get('entityType');
        }

        return $list;
    }

    public function jobClearCache()
    {
        $this->dataManager->clearCache();
    }

    public function jobRebuild()
    {
        $this->dataManager->rebuild();
    }

    /**
     * @todo Remove in 6.0.
     */
    public function jobPopulatePhoneNumberNumeric()
    {
        $numberList = $this->entityManager->getRepository('PhoneNumber')->find();
        foreach ($numberList as $number) {
            $this->entityManager->saveEntity($number);
        }
    }

    /**
     * @todo Remove in 6.0. Move to another place. CLI command.
     */
    public function jobPopulateArrayValues()
    {
        $scopeList = array_keys($this->metadata->get(['scopes']));

        $query = $this->entityManager->getQueryBuilder()
            ->delete()
            ->from('ArrayValue')
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);

        foreach ($scopeList as $scope) {
            if (!$this->metadata->get(['scopes', $scope, 'entity'])) continue;
            if ($this->metadata->get(['scopes', $scope, 'disabled'])) continue;

            $seed = $this->entityManager->getEntity($scope);
            if (!$seed) continue;

            $attributeList = [];

            foreach ($seed->getAttributes() as $attribute => $defs) {
                if (!isset($defs['type']) || $defs['type'] !== Entity::JSON_ARRAY) continue;
                if (!$seed->getAttributeParam($attribute, 'storeArrayValues')) continue;
                if ($seed->getAttributeParam($attribute, 'notStorable')) continue;
                $attributeList[] = $attribute;
            }
            $select = ['id'];
            $orGroup = [];

            foreach ($attributeList as $attribute) {
                $select[] = $attribute;
                $orGroup[$attribute . '!='] = null;
            }

            $repository = $this->entityManager->getRepository($scope);

            if (! $repository instanceof RDBRepository) {
                continue;
            }

            if (!count($attributeList)) {
                continue;
            }

            $query = $this->entityManager->getQueryBuilder()
                ->select()
                ->from($scope)
                ->select($select)
                ->where([
                    'OR' => $orGroup,
                ])
                ->build();

            $sth = $this->entityManager->getQueryExecutor()->execute($query);

            while ($dataRow = $sth->fetch()) {
                $entity = $this->entityManager->getEntityFactory()->create($scope);
                $entity->set($dataRow);
                $entity->setAsFetched();

                foreach ($attributeList as $attribute) {
                    $this->entityManager->getRepository('ArrayValue')->storeEntityAttribute($entity, $attribute, true);
                }
            }
        }
    }

    /**
     * @todo Remove in 6.0. Move to another place. CLI command.
     */
    public function jobPopulateOptedOutPhoneNumbers()
    {
        $entityTypeList = ['Contact', 'Lead'];

        foreach ($entityTypeList as $entityType) {
            $entityList = $this->entityManager
                ->getRepository($entityType)
                ->where([
                    'doNotCall' => true,
                    'phoneNumber!=' => null,
                ])
                ->select(['id', 'phoneNumber'])
                ->find();

            foreach ($entityList as $entity) {
                $phoneNumber = $entity->get('phoneNumber');

                if (!$phoneNumber) {
                    continue;
                }

                $phoneNumberEntity = $this->entityManager->getRepository('PhoneNumber')->getByNumber($phoneNumber);

                if (!$phoneNumberEntity) {
                    continue;
                }

                $phoneNumberEntity->set('optOut', true);

                $this->entityManager->saveEntity($phoneNumberEntity);
            }
        }
    }

    protected function filterPreferencesData(StdClass $data)
    {
        $passwordFieldList = $this->fieldUtil->getFieldByTypeList('Preferences', 'password');

        foreach ($passwordFieldList as $field) {
            unset($data->$field);
        }
    }
}
