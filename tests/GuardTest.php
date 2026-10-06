<?php

declare(strict_types=1);

namespace VQueue\Connector\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VQueue\Connector\Acl;
use VQueue\Connector\Guard;
use VQueue\Connector\MemorySettingsCache;
use VQueue\Connector\QueuePass;

final class GuardTest extends TestCase
{
    private const PASS = 'eyJlIjoiZXYtNDIiLCJleHAiOjE3MDAwMDM2MDAsImlhdCI6MTcwMDAwMDAwMCwidCI6IjExMTExMTExLTExMTEtMTExMS0xMTExLTExMTExMTExMTExMSJ9'
        . '.AjaVlbsXru7V8GOJuhEV2doxd3W1-dQlEVTi5BEnNco';

    private const SECRET = 'test-private-key-abc123';

    /** El token de la cola es el id de la línea: un UUID. */
    private const TOKEN = '11111111-1111-1111-1111-111111111111';

    private const SETTINGS = [
        'data' => [
            'client' => 'orome',
            'queue_url' => 'https://orome.virtual-queue.com',
            'cookie_lifetime' => 600,
            'acls' => [[
                'action' => 'redirect_to_queue',
                'pattern' => '/shop',
                'pattern_type' => 'prefix',
                'event_id' => 'ev-42',
                'priority' => 0,
                'enabled' => true,
            ]],
        ],
    ];

    /** Dentro de la validez del vector de Elixir (exp = 1700003600). */
    private const NOW = 1_700_000_100;

    /** @param array<string, array> $responses URL fragment => cuerpo JSON decodificado */
    private function guard(array $responses = [], ?int $now = null): Guard
    {
        $responses['/adapter/'] ??= self::SETTINGS;

        $http = function (string $url) use ($responses): array {
            foreach ($responses as $fragment => $body) {
                if (str_contains($url, $fragment)) {
                    return $body;
                }
            }

            return [];
        };

        return new Guard(
            client: 'orome',
            privateKey: self::SECRET,
            adminHost: 'admin.test',
            cache: new MemorySettingsCache(),
            httpClient: \Closure::fromCallable($http),
            clock: fn () => $now ?? self::NOW,
            logger: static function (string $message): void {
            },
        );
    }

    private function request(array $overrides = []): array
    {
        return array_merge([
            'path' => '/shop/entradas',
            'query' => [],
            'cookies' => [],
            'method' => 'GET',
        ], $overrides);
    }

    public function test_manda_a_la_cola_al_visitante_sin_pase(): void
    {
        $decision = $this->guard()->decide($this->request());

        self::assertSame('redirect', $decision['type']);
        self::assertSame('https://orome.virtual-queue.com/queue/ev-42', $decision['location']);
    }

    public function test_guarda_el_destino_para_poder_volver(): void
    {
        $decision = $this->guard()->decide($this->request([
            'path' => '/shop/entradas',
            'query' => ['fila' => '3'],
        ]));

        $target = $decision['cookies'][0];
        self::assertSame(Guard::targetCookieName('ev-42'), $target['name']);
        self::assertSame('/shop/entradas?fila=3', $target['value']);
    }

    public function test_deja_pasar_al_que_tiene_pase_valido_y_lo_renueva(): void
    {
        $decision = $this->guard()->decide($this->request([
            'cookies' => [QueuePass::cookieName('ev-42') => self::PASS],
        ]));

        self::assertSame('allow', $decision['type']);
        self::assertSame(QueuePass::cookieName('ev-42'), $decision['renew']['name']);
        self::assertSame(600, $decision['renew']['maxAge']);
    }

    public function test_con_pase_vencido_vuelve_a_la_cola(): void
    {
        // Un segundo después del exp del vector.
        $decision = $this->guard(now: 1_700_003_601)->decide($this->request([
            'cookies' => [QueuePass::cookieName('ev-42') => self::PASS],
        ]));

        self::assertSame('redirect', $decision['type']);
    }

    public function test_con_pase_falsificado_vuelve_a_la_cola(): void
    {
        $decision = $this->guard()->decide($this->request([
            'cookies' => [QueuePass::cookieName('ev-42') => 'falsificado.nope'],
        ]));

        self::assertSame('redirect', $decision['type']);
    }

    public function test_saltea_assets_sin_consultar_settings(): void
    {
        $guard = new Guard(
            client: 'orome',
            privateKey: self::SECRET,
            adminHost: 'admin.test',
            cache: new MemorySettingsCache(),
            httpClient: \Closure::fromCallable(static fn () => throw new \RuntimeException('no debe pedir settings')),
        );

        self::assertSame('bypass', $guard->decide($this->request(['path' => '/shop/app.css']))['type']);
    }

    public function test_no_saltea_json_son_endpoints_dinamicos(): void
    {
        self::assertSame(
            'redirect',
            $this->guard()->decide($this->request(['path' => '/shop/products.json']))['type'],
        );
    }

    public function test_no_redirige_un_post(): void
    {
        self::assertSame(
            'bypass',
            $this->guard()->decide($this->request(['method' => 'POST']))['type'],
        );
    }

    public function test_sin_settings_deja_pasar(): void
    {
        $guard = $this->guard(['/adapter/' => []]);

        self::assertSame('allow', $guard->decide($this->request())['type']);
    }

    public function test_sin_queue_url_no_hay_settings(): void
    {
        $settings = self::SETTINGS;
        $settings['data']['queue_url'] = null;

        self::assertSame('allow', $this->guard(['/adapter/' => $settings])->decide($this->request())['type']);
    }

    public function test_sin_config_no_encola_a_nadie(): void
    {
        $guard = new Guard(client: '', privateKey: '', cache: new MemorySettingsCache());

        self::assertFalse($guard->isConfigured());
        self::assertSame('allow', $guard->decide($this->request())['type']);
    }

    public function test_el_cache_por_defecto_se_resuelve_sin_apcu(): void
    {
        // Sin cache inyectado el Guard elige APCu o disco. Acá (CLI, sin APCu)
        // cae a disco, y construirlo no puede fallar: con las clases del cache
        // repartidas en archivos que se declaraban entre sí, esto era un fatal
        // "Cannot redeclare interface" en todo request de producción.
        $guard = new Guard(client: 'orome', privateKey: self::SECRET);

        self::assertTrue($guard->isConfigured());
    }

    public function test_canjea_el_token_y_limpia_la_query(): void
    {
        $guard = $this->guard([
            '/queue/verify' => [
                'success' => true,
                'data' => ['event_id' => 'ev-42', 'pass' => self::PASS],
            ],
        ]);

        $decision = $guard->decide($this->request([
            'query' => ['token' => self::TOKEN, 'sku' => '7'],
        ]));

        self::assertSame('redirect', $decision['type']);
        self::assertSame('/shop/entradas?sku=7', $decision['location']);
        self::assertSame(QueuePass::cookieName('ev-42'), $decision['cookies'][0]['name']);
        self::assertSame(self::PASS, $decision['cookies'][0]['value']);
    }

    public function test_vuelve_al_destino_guardado(): void
    {
        $guard = $this->guard([
            '/queue/verify' => ['success' => true, 'data' => ['event_id' => 'ev-42', 'pass' => self::PASS]],
        ]);

        $decision = $guard->decide($this->request([
            'path' => '/',
            'query' => ['vq_token' => self::TOKEN],
            'cookies' => [Guard::targetCookieName('ev-42') => '/shop/entradas?fila=3'],
        ]));

        self::assertSame('/shop/entradas?fila=3', $decision['location']);
    }

    public function test_un_token_ajeno_no_se_secuestra(): void
    {
        $guard = $this->guard(['/queue/verify' => ['success' => false]]);

        // /reset no matchea ninguna ACL: debe pasar al origen con su token intacto.
        $decision = $guard->decide($this->request([
            'path' => '/reset',
            'query' => ['token' => self::TOKEN],
        ]));

        self::assertSame('allow', $decision['type']);
    }

    public function test_un_token_que_no_es_uuid_ni_siquiera_toca_la_red(): void
    {
        $verifyCalls = 0;
        $http = function (string $url) use (&$verifyCalls): array {
            if (str_contains($url, '/queue/verify')) {
                $verifyCalls++;
            }

            return str_contains($url, '/adapter/') ? self::SETTINGS : [];
        };
        $guard = new Guard(
            client: 'orome',
            privateKey: self::SECRET,
            cache: new MemorySettingsCache(),
            httpClient: \Closure::fromCallable($http),
        );

        $decision = $guard->decide($this->request(['path' => '/reset', 'query' => ['token' => 'abc123']]));

        self::assertSame('allow', $decision['type']);
        self::assertSame(0, $verifyCalls);
    }

    public function test_sin_pase_en_la_respuesta_del_verify_el_canje_falla(): void
    {
        // Escribir el token crudo como pase produciría una cookie que nunca
        // valida: el visitante volvería a la cola en la página siguiente.
        $guard = $this->guard([
            '/queue/verify' => ['success' => true, 'data' => ['event_id' => 'ev-42', 'pass' => null, 'token' => self::TOKEN]],
        ]);

        $decision = $guard->decide($this->request(['query' => ['vq_token' => self::TOKEN]]));

        self::assertSame('redirect', $decision['type']);
        self::assertStringContainsString('/queue/ev-42', $decision['location']);
        self::assertSame(Guard::targetCookieName('ev-42'), $decision['cookies'][0]['name']);
    }

    public function test_ante_un_fallo_sirve_la_ultima_config_y_no_martilla_la_api(): void
    {
        $calls = 0;
        $fail = false;
        $now = self::NOW;

        $http = function () use (&$calls, &$fail): array {
            $calls++;

            return $fail ? [] : self::SETTINGS;
        };

        $guard = new Guard(
            client: 'orome',
            privateKey: self::SECRET,
            cache: new MemorySettingsCache(),
            settingsTtlSeconds: 30,
            httpClient: \Closure::fromCallable($http),
            clock: function () use (&$now) {
                return $now;
            },
        );

        self::assertSame('redirect', $guard->decide($this->request())['type']);
        self::assertSame(1, $calls);

        // Vencido y con la API caída: sigue protegiendo con la config anterior.
        $now += 60;
        $fail = true;
        self::assertSame('redirect', $guard->decide($this->request())['type']);
        self::assertSame(2, $calls);

        // Dentro del backoff no vuelve a golpear la API.
        self::assertSame('redirect', $guard->decide($this->request())['type']);
        self::assertSame(2, $calls);
    }

    public function test_sin_config_previa_un_fallo_deja_pasar_y_tampoco_martilla(): void
    {
        $calls = 0;
        $http = function () use (&$calls): array {
            $calls++;

            return [];
        };
        $guard = new Guard(
            client: 'orome',
            privateKey: self::SECRET,
            cache: new MemorySettingsCache(),
            httpClient: \Closure::fromCallable($http),
        );

        self::assertSame('allow', $guard->decide($this->request())['type']);
        self::assertSame('allow', $guard->decide($this->request())['type']);
        self::assertSame(1, $calls);
    }

    #[DataProvider('destinosPeligrosos')]
    public function test_no_abre_un_open_redirect(mixed $target): void
    {
        self::assertNull(Guard::safeTarget($target));
    }

    public static function destinosPeligrosos(): array
    {
        return [
            'absoluta' => ['https://malicioso.com'],
            'protocol-relative' => ['//malicioso.com'],
            'backslash (los browsers lo leen como //)' => ['/\\malicioso.com'],
            'crlf' => ["/ok\r\nSet-Cookie: x=1"],
            'relativa' => ['relativo'],
            'vacía' => [''],
            'null' => [null],
        ];
    }

    public function test_acepta_un_path_propio(): void
    {
        self::assertSame('/shop/entradas?fila=3', Guard::safeTarget('/shop/entradas?fila=3'));
    }

    #[DataProvider('vidasDeCookie')]
    public function test_normaliza_cookie_lifetime_como_el_worker(mixed $value, int $expected): void
    {
        self::assertSame($expected, Guard::resolveCookieLifetime($value));
    }

    public static function vidasDeCookie(): array
    {
        return [
            'normal' => [600, 600],
            'string numérico' => ['900', 900],
            'cero' => [0, 600],
            'negativo' => [-5, 600],
            'basura' => ['abc', 600],
            'null' => [null, 600],
            'tope 24h' => [999_999, 86400],
        ];
    }
}

final class AclTest extends TestCase
{
    public function test_gana_la_regla_de_mayor_prioridad(): void
    {
        $rules = Acl::sort([
            ['action' => 'redirect_to_queue', 'pattern' => '/shop', 'pattern_type' => 'prefix', 'priority' => 0, 'enabled' => true],
            ['action' => 'bypass', 'pattern' => '/shop/ayuda', 'pattern_type' => 'exact', 'priority' => -1, 'enabled' => true],
        ]);

        self::assertSame('bypass', Acl::firstMatch($rules, '/shop/ayuda')['action']);
        self::assertSame('redirect_to_queue', Acl::firstMatch($rules, '/shop/entradas')['action']);
    }

    public function test_descarta_las_reglas_deshabilitadas(): void
    {
        $rules = Acl::sort([
            ['action' => 'redirect_to_queue', 'pattern' => '/shop', 'pattern_type' => 'prefix', 'priority' => 0, 'enabled' => false],
        ]);

        self::assertSame([], $rules);
        self::assertNull(Acl::firstMatch($rules, '/shop'));
    }

    public function test_una_regla_sin_priority_va_al_final(): void
    {
        $rules = Acl::sort([
            ['action' => 'a', 'pattern' => '/x', 'pattern_type' => 'prefix', 'enabled' => true],
            ['action' => 'b', 'pattern' => '/x', 'pattern_type' => 'prefix', 'priority' => 5, 'enabled' => true],
        ]);

        self::assertSame('b', $rules[0]['action']);
    }

    public function test_el_glob_no_trata_el_punto_como_metacaracter(): void
    {
        $rule = ['pattern' => '/shop/*.html', 'pattern_type' => 'glob'];

        self::assertTrue(Acl::matches($rule, '/shop/entradas.html'));
        self::assertFalse(Acl::matches($rule, '/shop/entradasXhtml'));
    }

    public function test_un_pattern_vacio_no_matchea_nada(): void
    {
        // str_starts_with($path, '') es true para cualquier path: una regla mal
        // cargada encolaría el sitio entero.
        foreach (['prefix', 'exact', 'contains', 'glob'] as $type) {
            self::assertFalse(Acl::matches(['pattern' => '', 'pattern_type' => $type], '/shop'), $type);
        }
    }

    public function test_json_no_es_asset(): void
    {
        self::assertFalse(Acl::isAsset('/shop/products.json'));
        self::assertTrue(Acl::isAsset('/shop/app.css'));
    }
}
