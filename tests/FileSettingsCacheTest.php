<?php

declare(strict_types=1);

namespace VQueue\Connector\Tests;

use PHPUnit\Framework\TestCase;
use VQueue\Connector\FileSettingsCache;

final class FileSettingsCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vqueue-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_guarda_y_recupera(): void
    {
        $cache = new FileSettingsCache($this->dir);
        $cache->set('k', ['a' => 1], 60);

        self::assertSame(['a' => 1], $cache->get('k'));
        self::assertNull($cache->get('otra'));
    }

    public function test_una_entrada_vencida_no_se_sirve(): void
    {
        $cache = new FileSettingsCache($this->dir);
        $cache->set('k', ['a' => 1], 0);

        self::assertNull($cache->get('k'));
    }

    public function test_un_archivo_corrupto_no_rompe(): void
    {
        $cache = new FileSettingsCache($this->dir);
        $cache->set('k', ['a' => 1], 60);
        file_put_contents(glob($this->dir . '/*.json')[0], '{no es json');

        self::assertNull($cache->get('k'));
    }

    public function test_no_deja_temporales(): void
    {
        $cache = new FileSettingsCache($this->dir);
        $cache->set('k', ['a' => 1], 60);

        self::assertCount(1, glob($this->dir . '/*'));
    }
}
