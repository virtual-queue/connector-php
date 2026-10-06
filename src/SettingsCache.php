<?php

declare(strict_types=1);

namespace VQueue\Connector;

/**
 * Cache de los settings entre requests.
 *
 * Acá PHP se diferencia del resto de las integraciones. En Cloudflare Workers,
 * Lambda o Node hay un proceso vivo donde cachear en memoria; en PHP-FPM el
 * proceso muere con el request, así que un cache en memoria no sobrevive y cada
 * page view pagaría un round trip a la API.
 *
 * Por defecto se usa APCu (compartido por el pool de FPM) y, si no está, un
 * archivo en el directorio temporal. Quien tenga Redis o Memcached puede
 * inyectar su propio cache implementando esta interfaz.
 *
 * Una clase por archivo (PSR-4): Composer carga cada archivo cuando alguien
 * nombra SU clase, y una segunda declaración de la misma interfaz es un fatal.
 */
interface SettingsCache
{
    /** @return array|null El payload cacheado, o null si no hay o venció. */
    public function get(string $key): ?array;

    public function set(string $key, array $value, int $ttlSeconds): void;
}
