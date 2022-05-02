<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\Constants;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;
use Phalcon\Queue\Beanstalk;

/**
 * Класс для обработки запросов от ExampleCRM
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 * @property Amo|AmoRestApi amoSleep
 * @property Beanstalk      queue
 */
class ExampleCrmController extends Controller
{
    /**
     * Передает данные в ExampleCRM по сделке, переданной из виджета из amoCRM
     *
     * @link https://core.company.ru/Example/Example_crm/create/aaaaaa
     *
     * @throws \Exception
     *
     * @return bool
     */
    public function createAction(): bool
    {
        $this->response->send();

        $crmData = $this->request->getJsonRawBody(true) ? : [];
        if (!$crmData) {
            $this->log->warning('Получили не валидные данные из ExampleCRM: ' . $this->request->getRawBody());

            return false;
        }

        $this->log->notice('Получили данные из ExampleCRM: ' . print_r($crmData, true));

        $entityType = $crmData['entity'] ?? null;
        if (!$entityType) {
            $this->log->warning('Не получили тип сущности из ExampleCRM');

            return false;
        }

        switch ($entityType) {
            case 'dialing':
                $this->processDialingData($crmData);

                break;
            case 'contact':
                $this->processContactUpdateData($crmData);

                break;
            case 'company':
                $this->processCompanyUpdateData($crmData);

                break;
            case 'lead':
                $this->processLeadUpdateData($crmData);

                break;
        }

        return true;
    }

    /**
     * Обрабатывает список для обзвона из ExampleCRM
     *
     * @param array $crmData Данные из ExampleCRM
     *
     * @return bool
     */
    private function processDialingData(array $crmData): bool
    {
        $entities = $crmData['entities'] ?? [];
        if (!$entities) {
            $this->log->warning('Не получили сущности из листа обзвона. Выходим.');

            return false;
        }

        $this->queue->choose(Constants::TUBE_Example_CRM_DIALING);
        foreach ($entities as $entity) {
            $this->queue->put($entity);
        }

        return true;
    }

    /**
     * Получает данные о Контакте из ExampleCRM и обновляет Контакт в amoCRM
     *
     * @param array $crmData Данные из ExampleCRM
     *
     * @return bool
     */
    private function processContactUpdateData(array $crmData): bool
    {
        $contactId = (int)($crmData['user_id'] ?? null);
        if (!$contactId) {
            $this->log->warning('Не получили user_id для обновления из ExampleCRM');

            return false;
        }

        $this->queue->choose(Constants::TUBE_Example_CRM_CONTACT_UPDATE);
        $this->queue->put($crmData);

        return true;
    }

    /**
     * Получает данные о Компании из ExampleCRM и обновляет Компанию в amoCRM
     *
     * @param array $crmData Данные из ExampleCRM
     *
     * @return bool
     */
    private function processCompanyUpdateData(array $crmData): bool
    {
        $companyId = (int)($crmData['company_id'] ?? null);
        if (!$companyId) {
            $this->log->warning('Не получили company_id для обновления из ExampleCRM');

            return false;
        }

        $this->queue->choose(Constants::TUBE_Example_CRM_COMPANY_UPDATE);
        $this->queue->put($crmData);

        return true;
    }

    /**
     * Обновляет или создает Сделки в amoCRM по данным заказа из ExampleCRM
     *
     * @param array $crmData
     *
     * @return bool
     */
    private function processLeadUpdateData(array $crmData): bool
    {
        $this->queue->choose(Constants::TUBE_Example_CRM_LEAD_UPDATE);
        $this->queue->put($crmData);

        return true;
    }
}
