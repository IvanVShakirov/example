<?php

namespace App\User\Api\Controller;

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoException;
use App\Common\Library\Amo\AmoRestApi;
use App\User\Common\Constants;
use App\User\Common\HookCache;
use Phalcon\Logger\Adapter;
use Phalcon\Mvc\Controller;

/**
 * Класс для обработки запросов из виджета CreateLead
 *
 * @property Adapter        log
 * @property Amo|AmoRestApi amo
 */
class CreateLeadController extends Controller
{
    /**
     * Передает данные в ExampleCRM по сделке, переданной из виджета из amoCRM
     *
     * @link https://core.company.ru/Example/create_lead/send_to_Example_crm/aaaaaa
     *
     * @throws AmoException
     * @throws \Exception
     *
     * @return bool
     */
    public function sendToExampleCRMAction()
    {
        $this->response->send();

        $leadId = $this->request->getJsonRawBody(true)['lead_id'] ?? null;
        if (!$leadId) {
            $this->log->warning('Не удалось получить id Сделки из виджета: ' . $this->request->getRawBody());

            return false;
        }

        $this->log->notice("Получили запрос на добавление Сделки с id $leadId");

        $lead = $this->amo->getLead($leadId);
        if (!$lead) {
            $this->log->warning('Не удалось получить Сделку из amoCRM.');

            return false;
        }

        $formattedDate = null;
        $ddate         = $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DELIVERY_DATE);
        if ($ddate) {
            $formattedDate = (new \DateTimeImmutable($ddate, new \DateTimeZone(Constants::TIMEZONE)))->format('d.m.Y');
        }

        $dataFromAmoCrm = [
            'entity'     => 'lead',
            'lead_id'    => $leadId,
            'order_id'   => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_ORDER_ID) ? : null,
            'ddate'      => $formattedDate,
            'dtime'      => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DELIVERY_TIME) ? : null,
            'address'    => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_DELIVERY_ADDRESS) ? : null,
            'driver'     => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_COURIER) ? : null,
            'total'      => $lead['price'] ?? null,
            'payment_id' => $this->amo->getCustomFieldValue($lead, Constants::CF_LEAD_PAYMENT_TYPE) ? : null,
            'status'     => Constants::Example_CRM_STATUSES_BY_AMOCRM_STATUSES[(int)$lead['status_id']] ?? null,
        ];

        $companyId = $lead['linked_company_id'] ?? null;
        if ($companyId) {
            $company = $this->amo->getCompany($companyId) ? : [];
            if ($company) {
                $dataFromAmoCrm['company']    = $company['name'] ?? null;
                $dataFromAmoCrm['company_id'] = $this->amo->getCustomFieldValue(
                    $company,
                    Constants::CF_COMPANY_CLIENT_ID
                ) ? : null;
            }
        }

        $contactId = $lead['main_contact_id'] ?? null;
        if ($contactId) {
            $contact = $this->amo->getContact($contactId) ? : [];
            if ($contact) {
                $dataFromAmoCrm['fio']     = $contact['name'] ?? null;
                $dataFromAmoCrm['phone']   = $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_PHONE)
                    ? : null;
                $dataFromAmoCrm['email']   = $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_EMAIL)
                    ? : null;
                $dataFromAmoCrm['user_id'] = $this->amo->getCustomFieldValue($contact, Constants::CF_CONTACT_CLIENT_ID)
                    ? : null;
            }
        }

        if ($dataFromAmoCrm) {

            $curl  = curl_init();
            curl_setopt($curl, CURLOPT_URL, Constants::Example_CRM_URL_SEND_DATA);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($dataFromAmoCrm));
            $headers = array();
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            $response= curl_exec($curl);
            if (curl_errno($curl)) {
                $this->log->notice(
                    'Не получилось отправить данные в ExampleCRM через curl, ошибка:  ' . curl_error($curl) . "."
                );
            }
            curl_close($curl);
            $this->log->notice(
                'Данные ' . json_encode($dataFromAmoCrm) . " были отправлены в ExampleCRM с ответом: $response"
            );

            $decodedResponse = json_decode($response, true);
            $ExampleCrmOrderId    = (int)$decodedResponse['order_id'] ?? null;
            $ExampleCrmCompanyId  = (int)$decodedResponse['company_id'] ?? null;
            $ExampleCrmUserId     = (int)$decodedResponse['user_id'] ?? null;
            $ExampleCrmMenuId     = (int)$decodedResponse['menu_id'] ?? null;

            if ($ExampleCrmOrderId) {
                $this->amo->updateLead(
                    $leadId,
                    null,
                    null,
                    null,
                    null,
                    [
                        Constants::CF_LEAD_ORDER_ID => ['value' => $ExampleCrmOrderId],
                    ]
                );
                $this->log->notice("В Сделке $leadId обновили поле order_id на $ExampleCrmOrderId");
            }

            if ($ExampleCrmMenuId) {
                $noteId = $this->amo->addNote(
                    "Ссылка на меню: http://www.cp.Example.ru/?r=api%2Fmenu&id=$ExampleCrmMenuId",
                    $leadId,
                    $this->amo::ENTITY_TYPE_LEAD,
                    $this->amo::NOTE_TYPE_COMMON
                );
                $this->log->notice("Создали Примечание $noteId в Сделке $leadId");
            }

            if ($ExampleCrmCompanyId && $companyId) {
                $this->amo->updateCompany(
                    $companyId,
                    null,
                    null,
                    null,
                    [
                        Constants::CF_COMPANY_CLIENT_ID => ['value' => $ExampleCrmCompanyId],
                    ]
                );
                HookCache::addCompanyToCache($companyId);
                $this->log->notice("В Компании $companyId обновили поле client_id на $ExampleCrmCompanyId");
            }

            if ($ExampleCrmUserId && $contactId) {
                $this->amo->updateContact(
                    $contactId,
                    null,
                    null,
                    null,
                    null,
                    [
                        Constants::CF_CONTACT_CLIENT_ID => ['value' => $ExampleCrmUserId],
                    ]
                );
                HookCache::addContactToCache($contactId);
                $this->log->notice("В Контакте $contactId обновили поле client_id на $ExampleCrmUserId");
            }
        }

        return true;
    }
}
