<?php

declare(strict_types=1);

namespace VQueue\Connector\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Una declaración por archivo, con el nombre del archivo.
 *
 * Composer (PSR-4) carga `Foo.php` cuando alguien nombra `Foo`. Si ese archivo
 * además declara `Bar`, la próxima vez que se cargue `Bar.php` PHP muere con
 * "Cannot redeclare". Pasó: los cuatro archivos del cache se declaraban entre sí
 * y el Guard sin cache inyectado (el camino de producción) era un fatal en cada
 * request, mientras los tests —que inyectan el cache— seguían en verde.
 */
final class Psr4Test extends TestCase
{
    public function test_cada_archivo_declara_exactamente_su_clase(): void
    {
        foreach (glob(__DIR__ . '/../src/*.php') as $file) {
            $expected = basename($file, '.php');
            preg_match_all('/^(?:final |abstract )?(?:class|interface|trait|enum) (\w+)/m', file_get_contents($file), $m);

            self::assertSame([$expected], $m[1], basename($file) . ' debe declarar solo ' . $expected);
        }
    }
}
