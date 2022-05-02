<?php

namespace App\User\Common;

use Phalcon\Cache\Backend\Redis;
use Phalcon\Di;

/**
 * @property Redis cache
 */
abstract class HookCache
{
    /**
     * Формат ключа кеша в Редисе
     */
    protected const CACHE_KEY = 'Example:amo_webhook:cache:%s:%s';

    /**
     * Время жизни кеша
     */
    protected const CACHE_LIFETIME = 10;

    /**
     * Получение имения ключа для конкретной сущности
     *
     * @param string $entityName Тип сущности
     * @param int    $id         Уникальный идентификатор сущности
     *
     * @return string Ключ для хранения в Redis
     */
    private static function getCacheKey(string $entityName, int $id): string
    {
        return sprintf(self::CACHE_KEY, $entityName, $id);
    }

    /**
     * Получение кеш сервиса из DI
     *
     * @return Redis
     */
    private static function getCacheService(): Redis
    {
        $di = Di::getDefault();

        return $di->get('cache');
    }

    /**
     * Добавление ID сущностей в кеш
     *
     * @param $entityName string Тип сущности
     * @param $ids        array Добавляемые уникальные идентификатор
     *
     * @return bool
     */
    private static function addIdsToCache(string $entityName, array $ids): bool
    {
        $cacheService = self::getCacheService();
        foreach ($ids as $id) {
            $cacheService->save(self::getCacheKey($entityName, $id), true, self::CACHE_LIFETIME);
        }

        return true;
    }

    /**
     * Проверка сущности на наличие в кеше
     *
     * @param $entityName string Тип сущности
     * @param $id         int Уникальный идентификатор сущности
     *
     * @return bool
     */
    private static function inCache($entityName, $id): bool
    {
        return self::getCacheService()->exists(self::getCacheKey($entityName, $id));
    }

    /**
     * Проверка Контакта на наличие в кеше
     *
     * @param $id int Уникальный идентификатор сущности
     *
     * @return bool
     */
    public static function contactInCache($id): bool
    {
        return self::inCache('contact', $id);
    }

    /**
     * Проверка Компании на наличие в кеше
     *
     * @param $id int Уникальный идентификатор сущности
     *
     * @return bool
     */
    public static function companyInCache($id): bool
    {
        return self::inCache('company', $id);
    }

    /**
     * Проверка Сделки на наличие в кеше
     *
     * @param $id int Уникальный идентификатор сущности
     *
     * @return bool
     */
    public static function leadInCache($id): bool
    {
        return self::inCache('lead', $id);
    }

    /**
     * Добавление Сделки в кеш
     *
     * @param $ids int[] Добавляемые уникальные идентификаторы
     *
     * @return bool
     */
    public static function addLeadToCache(...$ids): bool
    {
        return self::addIdsToCache('lead', $ids);
    }

    /**
     * Добавление Компании в кеш
     *
     * @param $ids int[] Добавляемые уникальные идентификаторы
     *
     * @return bool
     */
    public static function addCompanyToCache(...$ids): bool
    {
        return self::addIdsToCache('company', $ids);
    }

    /**
     * Добавление Контакта в кеш
     *
     * @param $ids int[] Добавляемые уникальные идентификаторы
     *
     * @return bool
     */
    public static function addContactToCache(...$ids): bool
    {
        return self::addIdsToCache('contact', $ids);
    }
}
