<?php

namespace App\User\Cli\Task;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\Constants;
use App\User\Common\HookCache;
use Error;
use Exception;
use Phalcon\Cli\Task;
use Phalcon\Logger\Adapter;
use Phalcon\Queue\Beanstalk;
use Phalcon\Queue\Beanstalk\Job;
use React\EventLoop\Factory as Loop;

/**
 * Класс для работы с ExampleCRM
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 * @property array          config
 * @property Beanstalk      queue
 */
class ExampleCrmTask extends Task
{
    /**
     * Максимальное количество повторения джобы
     *
     * @const int
     */
    protected const QUEUE_REPS_MAX = 10;

    /**
     * Успешный результат обработки джобы
     *
     * @const string
     */
    private const JOB_RESULT_SUCCESS = 0;

    /**
     * Неуспешный результат обработки джобы с уходом на повтор
     *
     * @const string
     */
    private const JOB_RESULT_FAIL_WITH_REPEAT = 1;

    /**
     * Неуспешный результат обработки джобы без ухода на повтор
     *
     * @const string
     */
    private const JOB_RESULT_FAIL = 2;

    /**
     * Обрабатывает список для обзвона из ExampleCRM
     *
     * @example php public/cli/index.php user:Example:Example_crm:dialing:daemon
     *
     * @return bool
     */
    public function dialingAction(): bool
    {
        $loop  = Loop::create();
        $queue = $this->queue;
        $queue->watch(Constants::TUBE_Example_CRM_DIALING);

        $loop->addPeriodicTimer(
            5,
            function () use ($queue) {
                $job = $queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $body = $job->getBody();
                if (!isset($body['users'], $body['company_id'])) {
                    $this->log->error('Пришла джоба без company_id или users: ' . print_r($body, true));
                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);

                    return false;
                }

                try {
                    $result = $this->processDialingData($body);
                    $this->processJobByResult($job, $result);
                } catch (AmoException $exception) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $exception");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
                } catch (Error $error) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $error");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);
                }

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал остановки цикла: ' . SIGINT);
            }
        );

        $loop->run();

        return true;
    }

    /**
     * Обрабатывает джобы обновления Контакта
     *
     * @example php public/cli/index.php user:Example:Example_crm:contact_update:daemon
     *
     * @return bool
     */
    public function contactUpdateAction(): bool
    {
        $loop  = Loop::create();
        $queue = $this->queue;
        $queue->watch(Constants::TUBE_Example_CRM_CONTACT_UPDATE);

        $loop->addPeriodicTimer(
            5,
            function () use ($queue) {
                $job = $queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $body = $job->getBody();
                if (!isset($body['user_id'])) {
                    $this->log->error('Пришла джоба без user_id: ' . print_r($body, true));
                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);

                    return false;
                }

                try {
                    $result = $this->processContactUpdateData($body);
                    $this->processJobByResult($job, $result);
                } catch (AmoException $exception) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $exception");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
                } catch (Error $error) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $error");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);
                }

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал остановки цикла: ' . SIGINT);
            }
        );

        $loop->run();

        return true;
    }

    /**
     * Обрабатывает джобы обновления Сделки
     *
     * @example php public/cli/index.php user:Example:Example_crm:lead_update:daemon
     *
     * @return bool
     */
    public function leadUpdateAction(): bool
    {
        $loop  = Loop::create();
        $queue = $this->queue;
        $queue->watch(Constants::TUBE_Example_CRM_LEAD_UPDATE);

        $loop->addPeriodicTimer(
            5,
            function () use ($queue) {
                $job = $queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $body = $job->getBody();
                try {
                    $result = $this->processLeadUpdateData($body);
                    $this->processJobByResult($job, $result);
                } catch (AmoException $exception) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $exception");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
                } catch (Error $error) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $error");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);
                }

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал остановки цикла: ' . SIGINT);
            }
        );

        $loop->run();

        return true;
    }

    /**
     * Обрабатывает джобы обновления Компании
     *
     * @example php public/cli/index.php user:Example:Example_crm:company_update:daemon
     *
     * @return bool
     */
    public function companyUpdateAction(): bool
    {
        $loop  = Loop::create();
        $queue = $this->queue;
        $queue->watch(Constants::TUBE_Example_CRM_COMPANY_UPDATE);

        $loop->addPeriodicTimer(
            5,
            function () use ($queue) {
                $job = $queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $body = $job->getBody();
                if (!isset($body['company_id'])) {
                    $this->log->error('Пришла джоба без company_id: ' . print_r($body, true));
                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);

                    return false;
                }

                try {
                    $result = $this->processCompanyUpdateData($body);
                    $this->processJobByResult($job, $result);
                } catch (AmoException $exception) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $exception");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
                } catch (Error $error) {
                    $this->log->error("Ошибка при обработке данных из ExampleCRM: $error");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL);
                }

                return true;
            }
        );

        $loop->addSignal(
            SIGINT,
            $func = function () use ($loop, &$func) {
                $loop->removeSignal(SIGINT, $func);
                $loop->stop();
                $this->log->notice('Пришел сигнал остановки цикла: ' . SIGINT);
            }
        );

        $loop->run();

        return true;
    }

    /**
     * Обработка джобы обновления контакта
     *
     * @param array $crmData Данные от ExampleCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function processContactUpdateData($crmData): int
    {
        $ExampleContactId = (int)$crmData['user_id'];
        $foundContact   = $this->amo->findFirstContactByCustomField($ExampleContactId, Constants::CF_CONTACT_CLIENT_ID);
        if ($foundContact) {
            $fio         = $crmData['fio'] ?? null;
            $mapFullName = array_filter(
                [
                    $crmData['first_name'] ?? null,
                    $crmData['last_name'] ?? null,
                    $crmData['father_name'] ?? null,
                ]
            );
            $fullName    = implode(' ', $mapFullName) ? : 'Новый контакт';

            $this->amo->updateContact(
                $foundContact['id'],
                $fio ? : $fullName,
                null,
                null,
                null,
                [
                    Constants::CF_CONTACT_PHONE => ['value' => $crmData['phone'] ?? null, 'enum' => 'WORK'],
                    Constants::CF_CONTACT_EMAIL => ['value' => $crmData['email'] ?? null, 'enum' => 'WORK'],
                ]
            );
            HookCache::addContactToCache($foundContact['id']);
            $this->log->notice("Обновили Контакт {$foundContact['id']} по user_id $ExampleContactId");
        }

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Обработка джобы обновления компании
     *
     * @param array $crmData Данные от ExampleCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function processCompanyUpdateData($crmData): int
    {
        $ExampleCompanyId = (int)$crmData['company_id'];
        $foundCompany   = $this->amo->findFirstCompanyByCustomField($ExampleCompanyId, Constants::CF_COMPANY_CLIENT_ID);
        if (!$foundCompany) {
            return self::JOB_RESULT_SUCCESS;
        }

        $registrationDateTimestamp = $crmData['cdate'] ?? null;
        $registrationDate          = null;
        if ($registrationDateTimestamp) {
            $registrationDate = (new \DateTimeImmutable('now', new \DateTimeZone(Constants::TIMEZONE)))
                ->setTimestamp($registrationDateTimestamp)->format('d-m-Y');
        }

        $this->amo->updateCompany(
            $foundCompany['id'],
            $crmData['company'] ?? 'Новая компания',
            null,
            null,
            [
                Constants::CF_COMPANY_REGISTRATION_DATE => ['value' => $registrationDate],
            ]
        );
        HookCache::addCompanyToCache($foundCompany['id']);
        $this->log->notice("Обновили Компанию {$foundCompany['id']} по company_id $ExampleCompanyId");

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Обработка джобы обновления компании
     *
     * @param array $crmData Данные от ExampleCRM
     *
     * @throws AmoException
     * @throws Exception
     *
     * @return int
     */
    private function processLeadUpdateData(array $crmData): int
    {
        $contactIds       = [];
        $contactsToCreate = [];
        $orderId          = (int)($crmData['order_id'] ?? null);
        $ExampleContacts    = $crmData['users'] ?? [];
        $ExampleCompanyId   = (int)($crmData['company_id'] ?? null);

        if ($ExampleContacts) {
            foreach ($ExampleContacts as $ExampleContact) {
                $ExampleContactId = (int)($ExampleContact['user_id'] ?? null);
                if (!$ExampleContactId) {
                    continue;
                }

                $foundContact = $this->amo->findFirstContactByCustomField(
                    $ExampleContactId,
                    Constants::CF_CONTACT_CLIENT_ID
                );

                if ($foundContact) {
                    $contactIds[$ExampleContactId] = $foundContact['id'];
                    $this->log->notice("Нашли Контакт {$foundContact['id']} по user_id $ExampleContactId");
                }
            }
        }

        $registrationDateTimestamp = $crmData['cdate'] ?? null;
        $registrationDate          = null;
        if ($registrationDateTimestamp) {
            $registrationDate = (new \DateTimeImmutable('now', new \DateTimeZone(Constants::TIMEZONE)))
                ->setTimestamp($registrationDateTimestamp)->format('d-m-Y');
        }

        $companyId = null;
        if ($ExampleCompanyId) {
            $foundCompany = $this->amo->findFirstCompanyByCustomField(
                $ExampleCompanyId,
                Constants::CF_COMPANY_CLIENT_ID
            );
            if ($foundCompany) {
                $companyId = $foundCompany['id'];
                $this->log->notice("Нашли Компанию {$foundCompany['id']} по company_id $ExampleCompanyId");

                if ($registrationDate) {
                    $this->amo->updateCompany(
                        $companyId,
                        null,
                        null,
                        null,
                        [
                            Constants::CF_COMPANY_REGISTRATION_DATE => ['value' => $registrationDate],
                        ]
                    );
                    HookCache::addCompanyToCache($companyId);
                    $this->log->notice(
                        "У Компании $companyId с company_id $ExampleCompanyId обновили Дату регистрации $registrationDate"
                    );
                }
            }
        }

        if (!$companyId && $ExampleCompanyId) {
            $companyId = $this->amo->addCompany(
                $crmData['company'] ?? 'Новая компания',
                null,
                null,
                [
                    Constants::CF_COMPANY_CLIENT_ID         => ['value' => $ExampleCompanyId],
                    Constants::CF_COMPANY_TYPE              => ['value' => $crmData['agencies'] ?? null],
                    Constants::CF_COMPANY_REGISTRATION_DATE => ['value' => $registrationDate],
                ]
            );
            HookCache::addCompanyToCache($companyId);
            $this->log->notice("Создали Компанию $companyId с company_id $ExampleCompanyId");
        }

        if (count($contactIds) !== count($ExampleContacts)) {
            foreach ($ExampleContacts as $ExampleContact) {
                $ExampleContactId = (int)($ExampleContact['user_id'] ?? null);
                if (!$ExampleContactId) {
                    continue;
                }

                if (isset($contactIds[$ExampleContactId])) {
                    continue;
                }

                $fio         = $ExampleContact['fio'] ?? null;
                $mapFullName = array_filter(
                    [
                        $ExampleContact['first_name'] ?? null,
                        $ExampleContact['last_name'] ?? null,
                        $ExampleContact['father_name'] ?? null,
                    ]
                );
                $fullName    = implode(' ', $mapFullName) ? : 'Новый контакт';

                $contactsToCreate[] = [
                    'name'              => $fio ? : $fullName,
                    'linked_company_id' => $companyId,
                    'custom_fields'     => [
                        [
                            'id'     => Constants::CF_CONTACT_PHONE,
                            'values' => [['value' => $ExampleContact['phone'] ?? null, 'enum' => 'WORK']],
                        ],
                        [
                            'id'     => Constants::CF_CONTACT_EMAIL,
                            'values' => [['value' => $ExampleContact['email'] ?? null, 'enum' => 'WORK']],
                        ],
                        [
                            'id'     => Constants::CF_CONTACT_CLIENT_ID,
                            'values' => [['value' => $ExampleContactId]],
                        ],
                    ],
                ];
            }
        }

        $ExampleStatusId = (int)($crmData['status_id'] ?? null);
        if (!$ExampleStatusId) {
            $this->log->warning('Не получили status_id от ExampleCRM');

            return self::JOB_RESULT_SUCCESS;
        }

        if (!isset(Constants::AMOCRM_STATUSES_BY_Example_CRM_STATUSES[$ExampleStatusId])) {
            $this->log->warning("Не получили статус amoCRM по status_id = $ExampleStatusId от ExampleCRM");

            return self::JOB_RESULT_SUCCESS;
        }

        $leadId = null;
        if ($orderId) {
            $foundLead = $this->amo->findFirstLeadByCustomField($orderId, Constants::CF_LEAD_ORDER_ID);
            if ($foundLead) {
                $leadId = $foundLead['id'];
                $this->log->notice("Нашли Сделку {$foundLead['id']} по order_id $orderId");
            }
        }

        $paymentId = (int)($crmData['payment_id'] ?? null);
        if ($leadId) {
            $leadNewStatusId = Constants::AMOCRM_STATUSES_BY_Example_CRM_STATUSES[$ExampleStatusId];
            $this->amo->updateLead(
                $leadId,
                null,
                $leadNewStatusId,
                $crmData['total'] ?? null,
                null,
                [
                    Constants::CF_LEAD_DELIVERY_DATE    => ['value' => $crmData['ddate'] ?? null],
                    Constants::CF_LEAD_DELIVERY_TIME    => ['value' => $crmData['dtime'] ?? null],
                    Constants::CF_LEAD_DELIVERY_ADDRESS => ['value' => $crmData['address'] ?? null],
                    Constants::CF_LEAD_COURIER          => ['value' => $crmData['driver'] ?? null],
                    Constants::CF_LEAD_PAYMENT_TYPE     => [
                        'value' => Constants::PAYMENT_TYPES_NAME_BY_ID[$paymentId] ?? null,
                    ],
                ]
            );
            $this->log->notice("У Сделки $leadId обновили статус на $leadNewStatusId и данные заказа $orderId");
        } else {
            $leadId = $this->amo->addLead(
                'Новая Сделка из ExampleCRM',
                [Constants::PIPELINE_DELIVERY => Constants::AMOCRM_STATUSES_BY_Example_CRM_STATUSES[$ExampleStatusId]],
                $crmData['total'] ?? 0,
                Constants::USER_EXAMPLE,
                [
                    Constants::CF_LEAD_ROISTAT          => [
                        'value' => $crmData['roistat'] ?? $crmData['company'] ?? null,
                    ],
                    Constants::CF_LEAD_ORDER_ID         => ['value' => $orderId],
                    Constants::CF_LEAD_DELIVERY_DATE    => ['value' => $crmData['ddate'] ?? null],
                    Constants::CF_LEAD_DELIVERY_TIME    => ['value' => $crmData['dtime'] ?? null],
                    Constants::CF_LEAD_DELIVERY_ADDRESS => ['value' => $crmData['address'] ?? null],
                    Constants::CF_LEAD_COURIER          => ['value' => $crmData['driver'] ?? null],
                    Constants::CF_LEAD_PAYMENT_TYPE     => [
                        'value' => Constants::PAYMENT_TYPES_NAME_BY_ID[$paymentId] ?? null,
                    ],
                ]
            );
            HookCache::addLeadToCache($leadId);
            $this->log->notice("Создали Сделку $leadId по order_id $orderId");

            if ($companyId) {
                $this->amo->updateCompanyAddLeads($companyId, [$leadId]);
                $this->log->notice("Связали Сделку $leadId и Компанию $companyId");
                HookCache::addCompanyToCache($companyId);
                HookCache::addLeadToCache($leadId);
            }

            if ($contactsToCreate) {
                $createdContacts = $this->amo->setContacts(['request' => ['contacts' => ['add' => $contactsToCreate]]]);
                $contactIds      = array_column($createdContacts['contacts']['add'] ?? [], 'id');
                $this->log->notice('Создали Контакты ' . implode(', ', $contactIds));
            }

            if ($contactIds) {
                $this->amo->linkLeadWithContacts($leadId, $contactIds);
                HookCache::addContactToCache(...$contactIds);
                HookCache::addLeadToCache($leadId);
                $this->log->notice("Связали Сделку $leadId и Контакты: " . implode(', ', $contactIds));
            }

            $noteText = '';
            $menuId   = $crmData['menu_id'] ?? null;
            if ($menuId) {
                $noteText .= "Ссылка на меню: http://www.cp.Example.ru/?r=api%2Fmenu&id=$menuId" . PHP_EOL;
            }

            $addressComment = $crmData['address_params']['comment'] ?? null;
            if ($addressComment) {
                $noteText .= "Комментарий: $addressComment" . PHP_EOL;
            }

            if ($noteText) {
                $noteId = $this->amo->addNote(
                    $noteText,
                    $leadId,
                    $this->amo::ENTITY_TYPE_LEAD,
                    $this->amo::NOTE_TYPE_COMMON
                );
                HookCache::addLeadToCache($leadId);
                $this->log->notice("Создали Примечание $noteId в Сделке $leadId");
            }
        }

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Обновляет или создает контакты по списку обзвона из ExampleCRM
     *
     * @param array $ExampleEntity Данные о компании из тела джобы
     *
     * @throws Exception
     *
     * @return int
     */
    private function processDialingData(array $ExampleEntity): int
    {
        $contactIds       = [];
        $contactsToCreate = [];
        $ExampleUsers       = $ExampleEntity['users'] ?? [];
        $comments         = $ExampleEntity['admin_comments'] ?? [];
        foreach ($ExampleUsers as $ExampleUser) {
            $ExampleContactId = (int)($ExampleUser['user_id'] ?? null);
            if (!$ExampleContactId) {
                continue;
            }

            $foundContact = $this->amo->findFirstContactByCustomField($ExampleContactId, Constants::CF_CONTACT_CLIENT_ID);
            if ($foundContact) {
                $contactIds[$ExampleContactId] = $foundContact['id'];
                $this->log->notice("Нашли Контакт {$foundContact['id']} по user_id $ExampleContactId");
            }
        }

        $timezone = new \DateTimeZone(Constants::TIMEZONE);
        $noteText = '';
        foreach ($comments as $comment) {
            $noteText .= sprintf(
                'Заказ на %s - %s (%s %s)' . PHP_EOL,
                (new \DateTimeImmutable('now', $timezone))->setTimestamp($comment['on_date'])->format('d.m.y'),
                $comment['comment'],
                $comment['admin'],
                (new \DateTimeImmutable('now', $timezone))->setTimestamp($comment['create_date'])->format('d.m.y H:i')
            );
        }

        $ordersCount = (int)($ExampleEntity['orders_count'] ?? null);

        $companyId               = null;
        $needToUpdateOrdersCount = false;
        $ExampleCompanyId          = $ExampleEntity['company_id'] ?? null;
        if ($ExampleCompanyId) {
            $foundCompany = $this->amo->findFirstCompanyByCustomField($ExampleCompanyId, Constants::CF_COMPANY_CLIENT_ID);
            if ($foundCompany) {
                $companyId = $foundCompany['id'];
                $this->log->notice("Нашли Компанию {$foundCompany['id']} по company_id $ExampleCompanyId");

                $ordersCountCompany = $this->amo->getCustomFieldValue(
                    $foundCompany,
                    Constants::CF_COMPANY_ORDERS_NUMBER
                );
                if ((int)$ordersCountCompany !== $ordersCount) {
                    $needToUpdateOrdersCount = true;
                }
            }
        }

        if (!$companyId && $ExampleCompanyId) {
            $companyId = $this->amo->addCompany(
                $ExampleEntity['company'] ?? 'Новая компания',
                null,
                null,
                [
                    Constants::CF_COMPANY_CLIENT_ID     => ['value' => $ExampleCompanyId],
                    Constants::CF_COMPANY_ORDERS_NUMBER => ['value' => $ordersCount],
                ]
            );
            HookCache::addCompanyToCache($companyId);
            $this->log->notice("Создали Компанию $companyId с company_id $ExampleCompanyId");
        }

        $ExampleUserFio = null;
        if ($ExampleUsers) {
            foreach ($ExampleUsers as $ExampleUser) {
                $ExampleContactId = (int)($ExampleUser['user_id'] ?? null);
                if (!$ExampleContactId) {
                    continue;
                }

                $fio         = $ExampleUser['fio'] ?? null;
                $mapFullName = array_filter(
                    [
                        $ExampleUser['first_name'] ?? null,
                        $ExampleUser['last_name'] ?? null,
                        $ExampleUser['father_name'] ?? null,
                    ]
                );
                $fullName    = implode(' ', $mapFullName) ? : 'Новый контакт';

                if (null === $ExampleUserFio) {
                    $ExampleUserFio = $fullName;
                }

                if (isset($contactIds[$ExampleContactId])) {
                    continue;
                }

                $contactsToCreate[] = [
                    'name'              => $fio ? : $fullName,
                    'linked_company_id' => $companyId,
                    'custom_fields'     => [
                        [
                            'id'     => Constants::CF_CONTACT_PHONE,
                            'values' => [['value' => $ExampleUser['phone'] ?? null, 'enum' => 'WORK']],
                        ],
                        [
                            'id'     => Constants::CF_CONTACT_EMAIL,
                            'values' => [['value' => $ExampleUser['email'] ?? null, 'enum' => 'WORK']],
                        ],
                        [
                            'id'     => Constants::CF_CONTACT_CLIENT_ID,
                            'values' => [['value' => $ExampleContactId]],
                        ],
                    ],
                ];
            }
        }

        $leadName = ($ExampleEntity['company'] ?? $ExampleEntity['address'] ?? '');
        if (1 === count($ExampleUsers)) {
            $leadName .= ' ' . $ExampleUserFio;
        }

        $leadId = $this->amo->addLead(
            $leadName ? : 'Новая Сделка из ExampleCRM',
            Constants::STATUS_Example_CALL_NO_ORDER
        );
        HookCache::addLeadToCache($leadId);
        $this->log->notice("Создали Сделку $leadId");

        if ($companyId) {
            $this->amo->updateCompanyAddLeads($companyId, [$leadId]);
            HookCache::addCompanyToCache($companyId);
            HookCache::addLeadToCache($leadId);

            $this->log->notice("Связали Сделку $leadId и Компанию $companyId");

            if ($needToUpdateOrdersCount) {
                $this->amo->updateCompany(
                    $companyId,
                    null,
                    null,
                    null,
                    [
                        Constants::CF_COMPANY_ORDERS_NUMBER => ['value' => $ordersCount],
                    ]
                );
                HookCache::addCompanyToCache($companyId);
                $this->log->notice(
                    "Обновили Компанию $companyId с company_id $ExampleCompanyId добавили кол-во заказов $ordersCount"
                );
            }
        }

        if ($contactsToCreate) {
            $createdContacts = $this->amo->setContacts(['request' => ['contacts' => ['add' => $contactsToCreate]]]);
            $contactIds      = array_column($createdContacts['contacts']['add'] ?? [], 'id');
            $this->log->notice('Создали Контакты ' . implode(', ', $contactIds));
        }

        if ($contactIds) {
            $this->amo->linkLeadWithContacts($leadId, $contactIds);
            HookCache::addContactToCache(...$contactIds);
            HookCache::addLeadToCache($leadId);
            $this->log->notice("Связали Сделку $leadId и Контакты: " . implode(', ', $contactIds));
        }

        if ($noteText) {
            $noteId = $this->amo->addNote(
                $noteText,
                $leadId,
                $this->amo::ENTITY_TYPE_LEAD,
                $this->amo::NOTE_TYPE_COMMON
            );
            HookCache::addLeadToCache($leadId);
            $this->log->notice("Создали Примечание $noteId в Сделке $leadId");
        }

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Метод для повторения джобы в зависимости от результата её обработки.
     *
     * @param Job $job    Джоба, которую обработали
     * @param int $result Результат обработки джобы
     *
     * @return bool
     */
    private function processJobByResult(Job $job, $result): bool
    {
        $jobId = $job->getId();

        if ($result === self::JOB_RESULT_SUCCESS) {
            $this->log->notice("Джоба $jobId успешно обработана. Она удаляется.");

            $job->delete();
        } elseif ($result === self::JOB_RESULT_FAIL_WITH_REPEAT) {
            $jobReleasesNum = $job->stats()['releases'] ?? 0;
            if ($jobReleasesNum < self::QUEUE_REPS_MAX) {
                $this->log->notice(
                    "Джоба $jobId неуспешно обработана. Уходит на повтор: $jobReleasesNum/" . self::QUEUE_REPS_MAX
                );

                $job->release(100, 5);
            } else {
                $this->log->notice(
                    "Джоба $jobId неуспешно обработана. Лимит повторения  $jobReleasesNum исчерпан. Она удаляется."
                );

                $job->delete();
            }
        } elseif ($result === self::JOB_RESULT_FAIL) {
            $this->log->warning('Джоба завершилась с неисправимой ошибкой');
            $job->delete();
        } else {
            $this->log->error("Неизвестный результат джобы: {$result}.");
            $job->delete();
        }

        return true;
    }
}
