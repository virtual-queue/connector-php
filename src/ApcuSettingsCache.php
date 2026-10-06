<?php

declare(strict_types=1);

namespace VQueue\Connector;

/** APCu: compartido entre requests del mismo pool de FPM. Es el mejor caso. */
final class ApcuSettingsCache implements SettingsCache
{
    public static function isAvailable(): bool
    {
        if (!function_exists('apcu_fetch') || !filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        // En CLI APCu suele estar apagado aunque la extensión cargue: apcu_store
        // devolvería false en silencio y cada request iría a la red.
        return PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL);
    }

    public function get(string $key): ?array
    {
        $ok = false;
        $value = apcu_fetch($key, $ok);

        return $ok && is_array($value) ? $value : null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        apcu_store($key, $value, $ttlSeconds);
    }
}
