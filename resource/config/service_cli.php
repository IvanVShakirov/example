<?php
/**
 * Сервисы, что размещаем в DI
 */

use App\Common\Library\Amo\Amo;
use App\Common\Library\Amo\AmoRestApi;

return [
    ['amo', function () {
        $config = $this->get('config');

        return new Amo(
            new AmoRestApi(
                $config['amo']['domain'],
                $config['amo']['email'],
                $config['amo']['hash'],
            )
        );
    }, true],
];
