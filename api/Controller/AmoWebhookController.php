<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\Constants;
use App\User\Common\HookCache;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;
use Phalcon\Queue\Beanstalk;

/**
 * Класс для обработки вебхуков от amoCRM
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 * @property Beanstalk      queue
 */
class AmoWebhookController extends Controller
{
    /**
     * Обрабатывает вебхук об изменении Контакта из amoCRM
     *
     * @link https://core.company.ru/Example/amo_webhook/contact_change/aaaa
     *
     * @return bool
     */
    public function contactChangeAction(): bool
    {
        $this->response->send();

        $contactId = $this->request->get('contacts')['update'][0]['id'] ?? null;
        if (!$contactId) {
            $this->log->warning(
                'Получили некорректный хук об обновлении Контакта из amoCRM: ' . print_r($this->request->get(), true)
            );

            return false;
        }

        if (HookCache::contactInCache($contactId)) {
            $this->log->notice(
                "Контакт {$contactId} обновлен нами. Действия не требуются"
            );

            return false;
        }

        $this->queue->choose(Constants::TUBE_AMOCRM_WEBHOOK);
        $this->queue->put(
            [
                'method_name' => 'process_contact_update',
                'entity_id'   => $contactId,
            ],
            [
                'delay' => Constants::DELAY_WEBHOOK_PROCESS,
            ]
        );
        $this->log->notice(
            "Закинули в очередь Контакт $contactId с задержкой в " . Constants::DELAY_WEBHOOK_PROCESS . 'c'
        );

        return true;
    }

    /**
     * Обрабатывает вебхук об изменении Компании из amoCRM
     *
     * @link https://core.company.ru/Example/amo_webhook/company_change/aaaaaa
     *
     * @return bool
     */
    public function companyChangeAction(): bool
    {
        $this->response->send();

        $companyId = $this->request->get('contacts')['update'][0]['id'] ?? null;
        if (!$companyId) {
            $this->log->warning(
                'Получили некорректный хук об обновлении Компании из amoCRM: ' . print_r($this->request->get(), true)
            );

            return false;
        }

        if (HookCache::companyInCache($companyId)) {
            $this->log->notice(
                "Компания {$companyId} обновлен нами. Действия не требуются"
            );

            return false;
        }

        $this->queue->choose(Constants::TUBE_AMOCRM_WEBHOOK);
        $this->queue->put(
            [
                'method_name' => 'process_company_update',
                'entity_id'   => $companyId,
            ],
            [
                'delay' => Constants::DELAY_WEBHOOK_PROCESS,
            ]
        );
        $this->log->notice(
            "Закинули в очередь Компанию $companyId с задержкой в " . Constants::DELAY_WEBHOOK_PROCESS . 'c'
        );

        return true;
    }

    /**
     * Обрабатывает вебхук об создании Сделки из amoCRM
     *
     * @link https://core.company.ru/Example/amo_webhook/lead_create/aaaaaa
     *
     * @return bool
     */
    public function leadCreateAction(): bool
    {
        $this->response->send();

        $leadId = (int)($this->request->get('leads')['add'][0]['id'] ?? null);
        if (!$leadId) {
            $this->log->warning(
                'Получили некорректный хук о создании Сделки из amoCRM: ' . print_r($this->request->get(), true)
            );

            return false;
        }

        if (HookCache::leadInCache($leadId)) {
            $this->log->notice(
                "Сделка {$leadId} обновлена нами. Действия не требуются"
            );

            return false;
        }

        $this->queue->choose(Constants::TUBE_AMOCRM_WEBHOOK);
        $this->queue->put(
            [
                'method_name' => 'process_lead_create',
                'entity_id'   => $leadId,
            ],
            [
                'delay' => Constants::DELAY_WEBHOOK_PROCESS,
            ]
        );
        $this->log->notice("Закинули в очередь Сделку $leadId с задержкой в " . Constants::DELAY_WEBHOOK_PROCESS . 'c');

        return true;
    }
}
