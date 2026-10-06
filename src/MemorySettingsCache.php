<?php

declare(strict_types=1);

namespace VQueue\Connector;

/** Solo para tests y para runtimes persistentes (Octane, RoadRunner, Swoole). */
final class MemorySettingsCache implements SettingsCache
{
    /** @var array<string, array{expire: int, value: array}> */
    private array $entries = [];

    public function get(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;

        return $entry !== null && $entry['expire'] > time() ? $entry['value'] : null;
    }

    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->entries[$key] = ['expire' => time() + $ttlSeconds, 'value' => $value];
    }
}
