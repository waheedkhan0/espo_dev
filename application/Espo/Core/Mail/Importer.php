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

namespace Espo\Core\Mail;

use Laminas\Mime\Mime as Mime;

use Espo\Entities\Email;

use Espo\Core\{
    Mail\MessageWrapper,
    ORM\EntityManager,
    Utils\Config,
    Notificators\Notificator,
    Mail\Parsers\MailMimeParser,
};

use Espo\Notificators\EmailNotificator;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Imports an email message into CRM. Handles duplicate checking, parent look-up.
 */
class Importer
{
    private $entityManager;

    private $config;

    private $notificator;

    private $filtersMatcher;

    protected $defaultParserClassName = MailMimeParser::class;

    protected $parserClassName;

    public function __construct(
        EntityManager $entityManager, Config $config, ?Notificator $notificator = null, ?string $parserClassName = null
    ) {
        $this->entityManager = $entityManager;
        $this->config = $config;
        $this->notificator = $notificator;

        $this->filtersMatcher = new FiltersMatcher();

        $this->parserClassName = $parserClassName ?? $this->defaultParserClassName;
    }

    public function importMessage(
        MessageWrapper $message,
        ?string $assignedUserId = null,
        array $teamsIdList = [],
        array $userIdList = [],
        iterable $filterList = [],
        bool $fetchOnlyHeader = false,
        ?array $folderData = null
    ) : ?Email {
        $parser = $message->getParser();

        $parserClassName = $this->parserClassName;

        if (!$parser || get_class($parser) !== $parserClassName) {
            $parser = new $parserClassName($this->entityManager);
        }

        $email = $this->entityManager->getEntity('Email');

        $email->set('isBeingImported', true);

        $subject = '';

        if ($parser->checkMessageAttribute($message, 'subject')) {
            $subject = $parser->getMessageAttribute($message, 'subject');
        }

        if (!empty($subject) && is_string($subject)) {
            $subject = trim($subject);
        }

        if ($subject !== '0' && empty($subject)) {
            $subject = '(No Subject)';
        }

        $email->set('isHtml', false);
        $email->set('name', $subject);
        $email->set('status', 'Archived');
        $email->set('attachmentsIds', []);

        if ($assignedUserId) {
            $email->set('assignedUserId', $assignedUserId);
            $email->addLinkMultipleId('assignedUsers', $assignedUserId);
        }

        $email->set('teamsIds', $teamsIdList);

        if (!empty($userIdList)) {
            foreach ($userIdList as $uId) {
                $email->addLinkMultipleId('users', $uId);
            }
        }

        $fromAddressData = $parser->getAddressDataFromMessage($message, 'from');

        if ($fromAddressData) {
            $fromString = ($fromAddressData['name'] ? ($fromAddressData['name'] . ' ') : '') . '<' .
                $fromAddressData['address'] .'>';
            $email->set('fromString', $fromString);
        }

        $replyToData = $parser->getAddressDataFromMessage($message, 'reply-To');

        if ($replyToData) {
            $replyToString = ($replyToData['name'] ? ($replyToData['name'] . ' ') : '') . '<' . $replyToData['address'] .'>';
            $email->set('replyToString', $replyToString);
        }

        $fromArr = $parser->getAddressListFromMessage($message, 'from');
        $toArr = $parser->getAddressListFromMessage($message, 'to');
        $ccArr = $parser->getAddressListFromMessage($message, 'cc');
        $replyToArr = $parser->getAddressListFromMessage($message, 'reply-To');

        if (count($fromArr)) {
            $email->set('from', $fromArr[0]);
        }

        $email->set('to', implode(';', $toArr));
        $email->set('cc', implode(';', $ccArr));
        $email->set('replyTo', implode(';', $replyToArr));

        $addressNameMap = $parser->getAddressNameMap($message);
        $email->set('addressNameMap', $addressNameMap);

        if ($folderData) {
            foreach ($folderData as $uId => $folderId) {
                $email->setLinkMultipleColumn('users', 'folderId', $uId, $folderId);
            }
        }

        if ($this->filtersMatcher->match($email, $filterList, true)) {
            return null;
        }

        if ($parser->checkMessageAttribute($message, 'message-Id') && $parser->getMessageAttribute($message, 'message-Id')) {
            $messageId = $parser->getMessageMessageId($message);

            $email->set('messageId', $messageId);

            if ($parser->checkMessageAttribute($message, 'delivered-To')) {
                $email->set('messageIdInternal', $messageId . '-' . $parser->getMessageAttribute($message, 'delivered-To'));
            }

            if (stripos($messageId, '@espo-system') !== false) {
                return null;
            }
        }

        $duplicate = null;

        if ($duplicate = $this->findDuplicate($email)) {
            if ($duplicate->get('status') != 'Being Imported') {
                $duplicate = $this->entityManager->getEntity('Email', $duplicate->id);

                $this->processDuplicate($duplicate, $assignedUserId, $userIdList, $folderData, $teamsIdList);

                return $duplicate;
            }
        }

        if ($parser->checkMessageAttribute($message, 'date')) {
            try {
                $dt = new DateTime($parser->getMessageAttribute($message, 'date'));

                if ($dt) {
                    $dateSent = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $email->set('dateSent', $dateSent);
                }
            } catch (Exception $e) {}
        } else {
            $email->set('dateSent', date('Y-m-d H:i:s'));
        }

        if ($parser->checkMessageAttribute($message, 'delivery-Date')) {
            try {
                $dt = new DateTime($parser->getMessageAttribute($message, 'delivery-Date'));

                if ($dt) {
                    $deliveryDate = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    $email->set('delivery-Date', $deliveryDate);
                }
            } catch (Exception $e) {}
        }

        $inlineAttachmentList = [];

        if (!$fetchOnlyHeader) {
            $parser->fetchContentParts($email, $message, $inlineAttachmentList);

            if ($this->filtersMatcher->match($email, $filterList)) {
                return null;
            }
        } else {
            $email->set('body', 'Not fetched. The email size exceeds the limit.');
            $email->set('isHtml', false);
        }

        $parentFound = false;

        $replied = null;

        if (
            $parser->checkMessageAttribute($message, 'in-Reply-To') && $parser->getMessageAttribute($message, 'in-Reply-To')
        ) {
            $arr = explode(' ', $parser->getMessageAttribute($message, 'in-Reply-To'));
            $inReplyTo = $arr[0];

            if ($inReplyTo) {
                if ($inReplyTo[0] !== '<') {
                    $inReplyTo = '<' . $inReplyTo . '>';
                }

                $replied = $this->entityManager
                    ->getRepository('Email')
                    ->where([
                        'messageId' => $inReplyTo
                    ])
                    ->findOne();

                if ($replied) {
                    $email->set('repliedId', $replied->id);
                    $repliedTeamIdList = $replied->getLinkMultipleIdList('teams');
                    foreach ($repliedTeamIdList as $repliedTeamId) {
                        $email->addLinkMultipleId('teams', $repliedTeamId);
                    }
                }
            }
        }

        if ($parser->checkMessageAttribute($message, 'references') && $parser->getMessageAttribute($message, 'references')) {
            $references = $parser->getMessageAttribute($message, 'references');
            $delimiter = ' ';

            if (strpos($references, '>,')) {
                $delimiter = ',';
            }

            $arr = explode($delimiter, $references);

            foreach ($arr as $reference) {
                $reference = trim($reference);
                $reference = str_replace(['/', '@'], " ", trim($reference, '<>'));
                $parentType = $parentId = null;
                $emailSent = PHP_INT_MAX;
                $number = null;

                $n = sscanf($reference, '%s %s %d %d espo', $parentType, $parentId, $emailSent, $number);

                if ($n != 4) {
                    $n = sscanf($reference, '%s %s %d %d espo-system', $parentType, $parentId, $emailSent, $number);
                }

                if ($n == 4 && $emailSent < time()) {
                    if (!empty($parentType) && !empty($parentId)) {
                        if ($parentType == 'Lead') {
                            $parent = $this->entityManager->getEntity('Lead', $parentId);

                            if ($parent && $parent->get('status') == 'Converted') {
                                if ($parent->get('createdAccountId')) {
                                    $account = $this->entityManager->getEntity('Account', $parent->get('createdAccountId'));
                                    if ($account) {
                                        $parentType = 'Account';
                                        $parentId = $account->id;
                                    }
                                } else {
                                    if ($this->config->get('b2cMode')) {
                                        if ($parent->get('createdContactId')) {
                                            $contact = $this->entityManager->getEntity('Contact', $parent->get('createdContactId'));
                                            if ($contact) {
                                                $parentType = 'Contact';
                                                $parentId = $contact->id;
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $email->set('parentType', $parentType);
                        $email->set('parentId', $parentId);

                        $parentFound = true;
                    }
                }
            }
        }

        if (!$parentFound) {
            if ($replied && $replied->get('parentId') && $replied->get('parentType')) {
                $parentFound = $this->entityManager->getEntity($replied->get('parentType'), $replied->get('parentId'));

                if ($parentFound) {
                    $email->set('parentType', $replied->get('parentType'));
                    $email->set('parentId', $replied->get('parentId'));
                }
            }
        }
        if (!$parentFound) {
            $from = $email->get('from');

            if ($from) {
                $parentFound = $this->findParent($email, $from);
            }
        }
        if (!$parentFound) {
            if (!empty($replyToArr)) {
                $parentFound = $this->findParent($email, $replyToArr[0]);
            }
        }
        if (!$parentFound) {
            if (!empty($toArr)) {
                $parentFound = $this->findParent($email, $toArr[0]);
            }
        }

        if (!$duplicate) {
            $this->entityManager->getLocker()->lockExclusive('Email');

            if ($duplicate = $this->findDuplicate($email)) {
                $this->entityManager->getLocker()->rollback();

                if ($duplicate->get('status') != 'Being Imported') {
                    $duplicate = $this->entityManager->getEntity('Email', $duplicate->id);
                    $this->processDuplicate($duplicate, $assignedUserId, $userIdList, $folderData, $teamsIdList);

                    return $duplicate;
                }
            }
        }

        if ($duplicate) {
            $duplicate->set([
                'from' => $email->get('from'),
                'to' => $email->get('to'),
                'cc' => $email->get('cc'),
                'bcc' => $email->get('bcc'),
                'replyTo' => $email->get('replyTo'),
                'name' => $email->get('name'),
                'dateSent' => $email->get('dateSent'),
                'body' => $email->get('body'),
                'bodyPlain' => $email->get('bodyPlain'),
                'parentType' => $email->get('parentType'),
                'parentId' => $email->get('parentId'),
                'isHtml' => $email->get('isHtml'),
                'messageId' => $email->get('messageId'),
                'fromString' => $email->get('fromString'),
                'replyToString' => $email->get('replyToString'),
            ]);

            $this->entityManager->getRepository('Email')->fillAccount($duplicate);

            $this->processDuplicate($duplicate, $assignedUserId, $userIdList, $folderData, $teamsIdList);

            return $duplicate;
        }

        if (!$email->get('messageId')) {
            $email->setDummyMessageId();
        }

        $email->set('status', 'Being Imported');

        $this->entityManager->saveEntity($email, [
            'skipAll' => true,
            'keepNew' => true,
        ]);

        $this->entityManager->getLocker()->commit();

        if ($parentFound) {
            $parentType = $email->get('parentType');
            $parentId = $email->get('parentId');
            $emailKeepParentTeamsEntityList = $this->config->get('emailKeepParentTeamsEntityList', []);
            if ($parentId && in_array($parentType, $emailKeepParentTeamsEntityList)) {
                if ($this->entityManager->hasRepository($parentType)) {
                    $parent = $this->entityManager->getEntity($parentType, $parentId);
                    if ($parent) {
                        $parentTeamIdList = $parent->getLinkMultipleIdList('teams');
                        foreach ($parentTeamIdList as $parentTeamId) {
                            $email->addLinkMultipleId('teams', $parentTeamId);
                        }
                    }
                }
            }
        }

        $email->set('status', 'Archived');

        $this->entityManager->saveEntity($email, [
            'isBeingImported' => true
        ]);

        foreach ($inlineAttachmentList as $attachment) {
            $attachment->set([
                'relatedId' => $email->id,
                'relatedType' => 'Email',
                'field' => 'body',
            ]);
            $this->entityManager->saveEntity($attachment);
        }

        return $email;
    }

    protected function findParent(Email $email, $emailAddress)
    {
        $contact = $this->entityManager->getRepository('Contact')->where([
            'emailAddress' => $emailAddress
        ])->findOne();

        if ($contact) {
            if (!$this->config->get('b2cMode')) {
                if ($contact->get('accountId')) {
                    $email->set('parentType', 'Account');
                    $email->set('parentId', $contact->get('accountId'));

                    return true;
                }
            }
            $email->set('parentType', 'Contact');
            $email->set('parentId', $contact->id);

            return true;
        }
        else {
            $account = $this->entityManager->getRepository('Account')->where([
                'emailAddress' => $emailAddress
            ])->findOne();

            if ($account) {
                $email->set('parentType', 'Account');
                $email->set('parentId', $account->id);
                return true;
            } else {
                $lead = $this->entityManager->getRepository('Lead')->where([
                    'emailAddress' => $emailAddress
                ])->findOne();
                if ($lead) {
                    $email->set('parentType', 'Lead');
                    $email->set('parentId', $lead->id);
                    return true;
                }
            }
        }
    }

    protected function findDuplicate(Email $email) : ?Email
    {
        if (!$email->get('messageId')) {
            return null;
        }

        $duplicate = $this->entityManager->getRepository('Email')
            ->select(['id', 'status'])
            ->where([
                'messageId' => $email->get('messageId'),
            ])
            ->findOne();

        return $duplicate;
    }

    protected function processDuplicate(Email $duplicate, $assignedUserId, $userIdList, $folderData, $teamsIdList)
    {
        if ($duplicate->get('status') == 'Archived') {
            $this->entityManager->getRepository('Email')->loadFromField($duplicate);
            $this->entityManager->getRepository('Email')->loadToField($duplicate);
        }

        $duplicate->loadLinkMultipleField('users');
        $fetchedUserIdList = $duplicate->getLinkMultipleIdList('users');
        $duplicate->setLinkMultipleIdList('users', []);

        $processNoteAcl = false;

        if ($assignedUserId) {
            if (!in_array($assignedUserId, $fetchedUserIdList)) {
                $processNoteAcl = true;
                $duplicate->addLinkMultipleId('users', $assignedUserId);
            }
            $duplicate->addLinkMultipleId('assignedUsers', $assignedUserId);
        }

        if (!empty($userIdList)) {
            foreach ($userIdList as $uId) {
                if (!in_array($uId, $fetchedUserIdList)) {
                    $processNoteAcl = true;
                    $duplicate->addLinkMultipleId('users', $uId);
                }
            }
        }

        if ($folderData) {
            foreach ($folderData as $uId => $folderId) {
                if (!in_array($uId, $fetchedUserIdList)) {
                    $duplicate->setLinkMultipleColumn('users', 'folderId', $uId, $folderId);
                } else {
                    $this->entityManager->getRepository('Email')->updateRelation($duplicate, 'users', $uId, [
                        'folderId' => $folderId,
                    ]);
                }
            }
        }

        $duplicate->set('isBeingImported', true);

        $this->entityManager->getRepository('Email')->applyUsersFilters($duplicate);

        $this->entityManager->getRepository('Email')->processLinkMultipleFieldSave($duplicate, 'users', [
            'skipLinkMultipleRemove' => true,
            'skipLinkMultipleUpdate' => true,
        ]);

        $this->entityManager->getRepository('Email')->processLinkMultipleFieldSave($duplicate, 'assignedUsers', [
            'skipLinkMultipleRemove' => true,
            'skipLinkMultipleUpdate' => true,
        ]);

        if ($notificator = $this->notificator) {
            $notificator->process($duplicate, [
                'isBeingImported' => true,
            ]);
        }

        $fetchedTeamIdList = $duplicate->getLinkMultipleIdList('teams');

        if (!empty($teamsIdList)) {
            foreach ($teamsIdList as $teamId) {
                if (!in_array($teamId, $fetchedTeamIdList)) {
                    $processNoteAcl = true;
                    $this->entityManager->getRepository('Email')->relate($duplicate, 'teams', $teamId);
                }
            }
        }

        if ($duplicate->get('parentType') && $processNoteAcl) {
            $dt = new DateTime();
            $dt->modify('+5 seconds');

            $executeAt = $dt->format('Y-m-d H:i:s');

            $job = $this->entityManager->getEntity('Job');
            $job->set([
                'serviceName' => 'Note',
                'methodName' => 'processNoteAclJob',
                'data' => [
                    'targetType' => 'Email',
                    'targetId' => $duplicate->id
                ],
                'executeAt' => $executeAt,
                'queue' => 'q1',
            ]);
            $this->entityManager->saveEntity($job);
        }
    }
}
