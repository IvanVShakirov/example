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
use Phalcon\Text;
use React\EventLoop\Factory as Loop;

/**
 * Класс для работы с amoCRM
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 * @property array          config
 * @property Beanstalk      queue
 */
class AmocrmTask extends Task
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
    public const JOB_RESULT_FAIL_WITH_REPEAT = 1;

    /**
     * Обрабатывает вебхук о создании Сделки в amoCRM
     *
     * @example php public/cli/index.php user:Example:amocrm:run:daemon
     *
     * @return bool
     */
    public function runAction(): bool
    {
        $loop  = Loop::create();
        $queue = $this->queue;
        $queue->watch(Constants::TUBE_AMOCRM_WEBHOOK);

        $loop->addPeriodicTimer(
            5,
            function () use ($queue) {
                $job = $queue->reserve(1);
                if ($job === false) {
                    return false;
                }

                $body = $job->getBody();
                if (!isset($body['entity_id'], $body['method_name'])) {
                    $this->log->error('Пришла джоба без entity_id или method_name: ' . print_r($body, true));

                    return false;
                }

                $methodName = Text::camelize($body['method_name']);
                if (!method_exists($this, $methodName)) {
                    $this->log->error('Вызван несуществующий метод ' . $methodName . print_r($body, true));

                    return false;
                }

                try {
                    /** @uses processLeadCreate */
                    /** @uses processCompanyUpdate */
                    /** @uses processContactUpdate */
                    $result = $this->{$methodName}($body['entity_id']);
                    $this->processJobByResult($job, $result);
                } catch (Exception $exception) {
                    $this->log->error("Ошибка при обработке данных из amoCRM: $exception");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
                } catch (Error $error) {
                    $this->log->error("Ошибка при обработке данных из amoCRM: $error");

                    $this->processJobByResult($job, self::JOB_RESULT_FAIL_WITH_REPEAT);
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
     * Обновляет статус связанных Сделок
     *
     * @param int $leadId Id Сделки из вебхука о создании Сделки из amoCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function processLeadCreate(int $leadId): int
    {
        $this->log->notice("Получили хук о создании Сделки c id $leadId из amoCRM");

        $lead = $this->amo->getLead($leadId);
        if (!$lead) {
            $this->log->warning("Не удалось получить Сделку с id $leadId по API из amoCRM");

            return self::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $leadStatus = (int)($lead['status_id'] ?? null);
        if ($leadStatus !== Constants::STATUS_ORDER_RECEIVED) {
            $this->log->warning("Сделка $leadId создана на не отслеживаемом статусе $leadStatus. Выходим.");

            return self::JOB_RESULT_SUCCESS;
        }

        $linkedContacts = $this->amo->getContactsByLead($leadId);
        if (!$linkedContacts) {
            return self::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $linkedLeadsIds = [];
        foreach ($linkedContacts as $linkedContact) {
            $linkedLeadsIds[] = $linkedContact['linked_leads_id'];
        }
        $linkedLeadsIds = array_merge(...$linkedLeadsIds);

        $linkedLeads = $this->amo->getLeadsList(null, null, $linkedLeadsIds)['leads'] ?? [];
        if (!$linkedLeads) {
            return self::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $leadsToUpdate = [];
        foreach ($linkedLeads as $linkedLead) {
            $linkedLeadPipelineId = (int)($linkedLead['pipeline_id'] ?? null);
            $linkedLeadStatusId   = (int)($linkedLead['status_id'] ?? null);
            if ($linkedLeadStatusId === $this->amo::STATUS_SUCCESS
                || $linkedLeadStatusId === $this->amo::STATUS_FAIL
                || $linkedLeadPipelineId !== Constants::PIPELINE_Example_CALL
            ) {
                continue;
            }

            $linkedLeadId    = (int)$linkedLead['id'];
            $leadsToUpdate[] = [
                'id'          => $linkedLeadId,
                'pipeline_id' => Constants::PIPELINE_Example_CALL,
                'status_id'   => $this->amo::STATUS_SUCCESS,
            ];
            $this->log->notice("Добавили Сделку {$linkedLeadId} в массив обновления");
        }

        if ($leadsToUpdate) {
            $this->amo->setLeads(
                [
                    'request' => [
                        'leads' => [
                            'update' => array_map(
                                static function (array $entity) {
                                    $entity['last_modified'] = time();

                                    return $entity;
                                },
                                $leadsToUpdate
                            ),
                        ],
                    ],
                ]
            );
            HookCache::addLeadToCache(...array_column($leadsToUpdate, 'id'));
            $this->log->notice(
                'Перенесли Сделки  ' . implode(', ', array_column($leadsToUpdate, 'id'))
                . ' на статус успешно реализовано в воронке Смайл Список для обзвона'
            );
        }

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Обрабатывает вебхук об изменении Компании из amoCRM
     *
     * @param int $companyId Id Компании из вебхука об изменении Компании из amoCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function processCompanyUpdate($companyId)
    {
        $this->log->notice("Получили хук об изменении Компании c id $companyId из amoCRM");

        $company = $this->amo->getCompany($companyId);
        if (!$company) {
            $this->log->warning("Не удалось получить Компанию с id $companyId по API из amoCRM");

            return self::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $clientId = $this->amo->getCustomFieldValue($company, Constants::CF_COMPANY_CLIENT_ID) ? : null;
        if (!$clientId) {
            $this->log->warning("Компания с id $companyId не имеет company_id.");

            return self::JOB_RESULT_SUCCESS;
        }

        $dataFromAmoCrm = [
            'entity'     => 'company',
            'company'    => $company['name'] ?? null,
            'company_id' => $clientId,
        ];

        $this->sendToExampleCRM($dataFromAmoCrm);

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Обрабатывает вебхук об изменении Контакта из amoCRM
     *
     * @param int $contactId Id Контакта из вебхука об изменении Контакта из amoCRM
     *
     * @throws AmoException
     *
     * @return int
     */
    private function processContactUpdate($contactId)
    {
        $this->log->notice("Получили хук об изменении Контакта c id $contactId из amoCRM");

        $contact = $this->amo->getContact($contactId);
        if (!$contact) {
            $this->log->warning("Не удалось получить Контакт с id $contactId по API из amoCRM");

            return self::JOB_RESULT_FAIL_WITH_REPEAT;
        }

        $userId = $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_CLIENT_ID) ? : null;
        if (!$userId) {
            $this->log->warning("Контакт с id $contactId не имеет user_id.");

            return self::JOB_RESULT_SUCCESS;
        }

        $ExampleCompanyId  = null;
        $linkedCompanyId = $contact['linked_company_id'] ?? null;
        if ($linkedCompanyId) {
            $linkedCompany = $this->amo->getCompany($linkedCompanyId);
            if ($linkedCompany) {
                $ExampleCompanyId = $this->amo->getCustomFieldValue($linkedCompany, Constants::CF_COMPANY_CLIENT_ID);
            }
        }

        $dataFromAmoCrm = [
            'entity'     => 'contact',
            'fio'        => $contact['name'] ?? null,
            'phone'      => $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_PHONE) ? : null,
            'email'      => $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_EMAIL) ? : null,
            'user_id'    => $userId,
            'company_id' => $ExampleCompanyId,
        ];

        $this->sendToExampleCRM($dataFromAmoCrm);

        return self::JOB_RESULT_SUCCESS;
    }

    /**
     * Отправляет данные в ExampleCRM
     *
     * @param array $dataToSend Данные для отправки в ExampleCRM
     *
     * @return bool
     */
    private function sendToExampleCRM(array $dataToSend): bool
    {
        if (!$dataToSend) {
            return false;
        }

        $jsonEncodedData = json_encode($dataToSend, JSON_THROW_ON_ERROR, 512);

        $curl = curl_init(Constants::Example_CRM_URL_SEND_DATA);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonEncodedData);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        /** @noinspection CurlSslServerSpoofingInspection */
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $response = curl_exec($curl);
        curl_close($curl);

        $this->log->notice(
            "Данные $jsonEncodedData были отправлены в ExampleCRM с ответом: $response"
        );

        return true;
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

                $job->release(100, Constants::DELAY_WEBHOOK_PROCESS);
            } else {
                $this->log->notice(
                    "Джоба $jobId неуспешно обработана. Лимит повторения  $jobReleasesNum исчерпан. Она удаляется."
                );

                $job->delete();
            }
        } else {
            $this->log->warning("Неизвестный результат джобы: {$result}.");
            $job->delete();
        }

        return true;
    }
}
