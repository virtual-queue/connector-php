<?php

declare(strict_types=1);

namespace VQueue\Connector;

/**
 * Fallback a disco. Más lento que APCu pero sigue evitando el round trip, que es
 * lo que importa: leer un archivo local es órdenes de magnitud más barato que
 * una llamada HTTP a la API en cada page view.
 *
 * El nombre del archivo sale de un hash de la clave, y la clave que arma el Guard
 * incluye la private_key: en un hosting compartido, /tmp es escribible por todos
 * y un vecino que pudiera adivinar el nombre dejaría ahí sus propios settings
 * (con SU queue_url) antes de que la app escriba los reales.
 */
final class FileSettingsCache implements SettingsCache
{
    public function __construct(private readonly string $directory = '')
    {
    }

    public function get(string $key): ?array
    {
        $path = $this->pathFor($key);
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!is_array($entry) || !isset($entry['expire'], $entry['value'])) {
            return null;
        }

        if ($entry['expire'] <= time() || !is_array($entry['value'])) {
            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $payload = json_encode(['expire' => time() + $ttlSeconds, 'value' => $value]);
        if ($payload === false) {
            return;
        }

        // Escritura atómica: sin esto, dos requests concurrentes pueden dejar un
        // archivo a medio escribir que el siguiente lee como JSON inválido.
        $path = $this->pathFor($key);
        $tmp = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
    }

    private function pathFor(string $key): string
    {
        $dir = $this->directory !== '' ? $this->directory : sys_get_temp_dir();

        return $dir . DIRECTORY_SEPARATOR . 'vqueue-' . hash('sha256', $key) . '.json';
    }
}
