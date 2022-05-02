<?php

namespace App\User\Common;

/**
 * Класс с константами
 */
final class Constants
{
    public const TIMEZONE = 'Europe/Moscow';

    public const TUBE_Example_CRM_DIALING        = 'Example_example_crm_dialing';
    public const TUBE_Example_CRM_CONTACT_UPDATE = 'Example_contact_update';
    public const TUBE_Example_CRM_COMPANY_UPDATE = 'Example_company_update';
    public const TUBE_Example_CRM_LEAD_UPDATE    = 'Example_lead_update';
    public const TUBE_AMOCRM_WEBHOOK          = 'Example_amocrm_webhook';
    public const DELAY_WEBHOOK_PROCESS        = 60;

    public const CF_LEAD_ORDER_ID         = 000000;
    public const CF_LEAD_DELIVERY_DATE    = 000000;
    public const CF_LEAD_DELIVERY_TIME    = 000000;
    public const CF_LEAD_DELIVERY_ADDRESS = 000000;
    public const CF_LEAD_COURIER          = 000000;
    public const CF_LEAD_PAYMENT_TYPE     = 000000;
    public const CF_LEAD_ROISTAT          = 000000;

    public const CF_CONTACT_CLIENT_ID = 000000;
    public const CF_CONTACT_PHONE     = 000000;
    public const CF_CONTACT_EMAIL     = 000000;

    public const CF_COMPANY_CLIENT_ID         = 000000;
    public const CF_COMPANY_TYPE              = 000000;
    public const CF_COMPANY_REGISTRATION_DATE = 000000;
    public const CF_COMPANY_ORDERS_NUMBER     = 000000;

    public const PIPELINE_DELIVERY   = 000000;
    public const PIPELINE_Example_CALL = 000000;

    public const STATUS_SUCCESS              = 142;
    public const STATUS_FAIL                 = 143;
    public const STATUS_ORDER_RECEIVED       = 000000;
    public const STATUS_ACCEPTED_BY_SERVICES = 000000;
    public const STATUS_Example_CALL_NO_ORDER  = 000000; //??

    public const Example_CRM_STATUSES_BY_AMOCRM_STATUSES = [
        self::STATUS_ORDER_RECEIVED       => 1,
        self::STATUS_ACCEPTED_BY_SERVICES => 2,
        self::STATUS_FAIL                 => 3,
        self::STATUS_SUCCESS              => 5,
    ];

    public const AMOCRM_STATUSES_BY_Example_CRM_STATUSES = [
        1 => self::STATUS_ORDER_RECEIVED,
        2 => self::STATUS_ACCEPTED_BY_SERVICES,
        3 => self::STATUS_FAIL,
        4 => self::STATUS_FAIL,
        5 => self::STATUS_SUCCESS,
    ];

    public const PAYMENT_TYPES_NAME_BY_ID = [
        1 => 'Наличный',
        2 => 'Безналичный',
        3 => 'Смешанный',
    ];

    public const Example_CRM_URL_SEND_DATA = 'https://cp.example.ru/index.php?r=api/amo-crm';

    public const USER_EXAMPLE = 7305376;
}
