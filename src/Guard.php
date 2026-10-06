<?php

declare(strict_types=1);

namespace VQueue\Connector;

/**
 * Protección de cola para apps PHP.
 *
 * Mismo contrato que el core JS, el conector de Lambda@Edge y el JS adapter;
 * cambia solo el lenguaje. Decide a partir de un request normalizado y devuelve
 * qué hacer, sin tocar la salida: de eso se encargan los adaptadores
 * (`Middleware` para PSR-15, o el uso directo en cualquier framework).
 *
 * Todo falla abierto. Si no se pueden traer los settings, si la API de verify no
 * responde, si el pase está roto: el visitante pasa. Un conector que rompe el
 * sitio del cliente es peor que uno que no encola.
 */
final class Guard
{
    public const TARGET_COOKIE_PREFIX = 'vq_target_';
    private const TARGET_TTL_SECONDS = 3600;

    /** Mismos límites que el Worker (`resolveCookieTtl`) y el JS adapter. */
    public const DEFAULT_COOKIE_LIFETIME = 600;
    public const MAX_COOKIE_LIFETIME = 86400;

    /** Cuánto se conserva la última config buena para servirla si la API falla. */
    private const STALE_MAX_SECONDS = 3600;
    /** Tras un fallo, cuánto se espera antes de volver a golpear la API. */
    private const RETRY_AFTER_SECONDS = 5;

    /**
     * El token de la cola es el id de la línea: un UUID. Mismo filtro que el JS
     * adapter. Sin él, cada `?token=` propio del sitio (reset de password, magic
     * link) costaría un round trip a /queue/verify por página, y cualquiera
     * podría hacer que el server del cliente golpee la API de VQueue a voluntad.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private readonly SettingsCache $cache;

    public function __construct(
        private readonly string $client,
        private readonly string $privateKey,
        private readonly string $adminHost = 'clients.virtual-queue.com',
        ?SettingsCache $cache = null,
        private readonly int $settingsTtlSeconds = 30,
        private readonly int $timeoutSeconds = 2,
        private readonly ?\Closure $httpClient = null,
        // Inyectable para poder testear vencimientos con un reloj fijo.
        private readonly ?\Closure $clock = null,
        // Recibe un string. Por defecto va al error_log de PHP.
        private readonly ?\Closure $logger = null,
    ) {
        $this->cache = $cache ?? (ApcuSettingsCache::isAvailable()
            ? new ApcuSettingsCache()
            : new FileSettingsCache());
    }

    public static function targetCookieName(string $eventId): string
    {
        return self::TARGET_COOKIE_PREFIX . $eventId;
    }

    public function isConfigured(): bool
    {
        return $this->client !== '' && $this->privateKey !== '';
    }

    /**
     * Decide qué hacer con un request.
     *
     * @param array{path: string, query: array, cookies: array, method: string, isWebsocket?: bool} $request
     * @return array{type: string, location?: string, cookies?: array, renew?: array}
     */
    public function decide(array $request): array
    {
        if (!$this->isConfigured()) {
            return ['type' => 'allow'];
        }

        $path = $request['path'] ?? '/';
        $method = strtoupper($request['method'] ?? 'GET');
        $query = $request['query'] ?? [];
        $cookies = $request['cookies'] ?? [];

        if ($this->isBypass($path, $method, (bool) ($request['isWebsocket'] ?? false))) {
            return ['type' => 'bypass'];
        }

        $settings = $this->settings();
        if ($settings === null) {
            return ['type' => 'allow'];
        }

        // 1) Vuelta de la cola con ?vq_token= (o el ?token= legacy)
        $token = self::queueToken($query);
        if ($token !== null) {
            $exchanged = $this->exchangeToken($token, $settings);

            if ($exchanged !== null) {
                $eventId = $exchanged['event_id'];
                $stored = self::safeTarget($cookies[self::targetCookieName($eventId)] ?? null);

                return [
                    'type' => 'redirect',
                    'location' => $stored ?? $path . self::queryWithoutToken($query),
                    'cookies' => [
                        [
                            'name' => QueuePass::cookieName($eventId),
                            'value' => $exchanged['pass'],
                            'maxAge' => $settings['cookie_lifetime'],
                        ],
                        ['name' => self::targetCookieName($eventId), 'value' => '', 'maxAge' => 0],
                    ],
                ];
            }
            // No era un token de cola: sigue el flujo normal de ACL. Así un
            // ?token= propio del sitio (reset de password, magic link) no se
            // secuestra, y un token vencido no genera el loop cola→sitio→cola.
        }

        // 2) ACLs
        $rule = Acl::firstMatch($settings['rules'], $path);
        if ($rule === null || ($rule['action'] ?? null) !== 'redirect_to_queue') {
            return ['type' => 'allow'];
        }

        $eventId = isset($rule['event_id']) ? (string) $rule['event_id'] : '';
        if ($eventId === '') {
            return ['type' => 'allow'];
        }

        // 3) ¿Ya tiene pase para este evento?
        $pass = QueuePass::validFor($cookies, $eventId, $this->privateKey, $this->now());
        if ($pass['ok']) {
            // Renovación deslizante: mientras el visitante navegue, el pase se
            // extiende. Sin esto es un presupuesto fijo desde que salió de la
            // fila y una compra lenta vuelve a la cola a mitad de camino.
            return [
                'type' => 'allow',
                'renew' => [
                    'name' => QueuePass::cookieName($eventId),
                    'value' => $cookies[QueuePass::cookieName($eventId)],
                    'maxAge' => $settings['cookie_lifetime'],
                ],
            ];
        }

        // 4) A la sala de espera
        return [
            'type' => 'redirect',
            'location' => rtrim($settings['queue_url'], '/') . '/queue/' . rawurlencode($eventId),
            'cookies' => [[
                'name' => self::targetCookieName($eventId),
                'value' => $path . self::queryString($query),
                'maxAge' => self::TARGET_TTL_SECONDS,
            ]],
        ];
    }

    /**
     * Solo aceptamos un path absoluto propio. Nunca una URL completa: si
     * dejáramos pasar "https://..." el conector sería un open redirect.
     */
    public static function safeTarget(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 2048) {
            return null;
        }
        // "//host" es una URL protocol-relative, y los browsers tratan "/\host"
        // exactamente igual.
        if (!str_starts_with($raw, '/') || str_starts_with($raw, '//') || str_starts_with($raw, '/\\')) {
            return null;
        }
        if (preg_match('/[\r\n]/', $raw) === 1) {
            return null;
        }

        return $raw;
    }

    /** El token de la cola, o null si no viene o no tiene forma de UUID. */
    public static function queueToken(array $query): ?string
    {
        $token = $query['vq_token'] ?? $query['token'] ?? null;

        return is_string($token) && preg_match(self::UUID_PATTERN, $token) === 1 ? $token : null;
    }

    public static function resolveCookieLifetime(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_COOKIE_LIFETIME;
        }
        $seconds = (int) $value;

        return $seconds <= 0 ? self::DEFAULT_COOKIE_LIFETIME : min($seconds, self::MAX_COOKIE_LIFETIME);
    }

    private function log(string $message): void
    {
        $this->logger !== null ? ($this->logger)($message) : error_log($message);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function isBypass(string $path, string $method, bool $isWebsocket): bool
    {
        if ($isWebsocket) {
            return true;
        }
        // Un 302 sobre un POST hace que el browser reintente como GET y pierda
        // el body del checkout.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return true;
        }

        return str_starts_with($path, '/api/') || Acl::isAsset($path);
    }

    /**
     * Settings con cache, última config buena como respaldo y backoff tras un
     * fallo. Sin el respaldo, una caída del admin dejaría el origen sin
     * protección; sin el backoff, cada page view pagaría el timeout entero.
     *
     * @return array{queue_url: string, cookie_lifetime: int, rules: array}|null
     */
    private function settings(): ?array
    {
        $key = $this->cacheKey();
        $now = $this->now();
        $entry = $this->cache->get($key);
        $entry = is_array($entry) ? $entry : [];

        if (($entry['fresh_until'] ?? 0) > $now || ($entry['retry_after'] ?? 0) > $now) {
            return $entry['settings'] ?? null;
        }

        $fresh = $this->fetchSettings();
        if ($fresh !== null) {
            $this->cache->set($key, [
                'settings' => $fresh,
                'fresh_until' => $now + $this->settingsTtlSeconds,
                'retry_after' => 0,
            ], self::STALE_MAX_SECONDS);

            return $fresh;
        }

        $stale = $entry['settings'] ?? null;
        $this->cache->set($key, [
            'settings' => $stale,
            'fresh_until' => 0,
            'retry_after' => $now + self::RETRY_AFTER_SECONDS,
        ], self::STALE_MAX_SECONDS);

        return is_array($stale) ? $stale : null;
    }

    /**
     * La clave lleva la private_key hasheada: en el fallback a disco el nombre
     * del archivo sale de acá, y no debe poder adivinarlo otro usuario del mismo
     * host (ver FileSettingsCache).
     */
    private function cacheKey(): string
    {
        return 'vqueue.settings.' . hash('sha256', $this->client . '|' . $this->adminHost . '|' . $this->privateKey);
    }

    /** @return array{queue_url: string, cookie_lifetime: int, rules: array}|null */
    private function fetchSettings(): ?array
    {
        $url = sprintf(
            'https://%s/api/v1/adapter/%s/settings',
            $this->adminHost,
            rawurlencode($this->client),
        );

        $body = $this->fetchJson($url);
        $data = $body['data'] ?? null;

        if (!is_array($data) || !isset($data['acls']) || !is_array($data['acls'])) {
            return null;
        }

        // Sin queue_url no hay a dónde mandar a nadie: mejor "sin settings" (y
        // fail-open explícito) que un redirect a "/queue/..." del propio sitio.
        $queueUrl = $data['queue_url'] ?? null;
        if (!is_string($queueUrl) || preg_match('#^https?://#i', $queueUrl) !== 1) {
            return null;
        }

        return [
            'queue_url' => $queueUrl,
            'cookie_lifetime' => self::resolveCookieLifetime($data['cookie_lifetime'] ?? null),
            'rules' => Acl::sort($data['acls']),
        ];
    }

    /** @return array{event_id: string, pass: string}|null */
    private function exchangeToken(string $token, array $settings): ?array
    {
        $url = rtrim($settings['queue_url'], '/')
            . '/api/v1/queue/verify?token=' . rawurlencode($token);

        $body = $this->fetchJson($url);
        $data = $body['data'] ?? null;

        if (empty($body['success']) || !is_array($data) || empty($data['event_id'])) {
            return null;
        }

        // Sin pase no hay nada que verificar offline. El JS adapter cae al token
        // crudo porque no puede verificar nada de todos modos; acá ese fallback
        // solo produciría una cookie que nunca valida y mandaría al visitante a
        // la cola en la página siguiente. Se trata como canje fallido.
        $pass = $data['pass'] ?? null;
        if (!is_string($pass) || $pass === '') {
            $this->log('[vqueue] VQueue no devolvió el pase: fallo al firmar del lado de la cola');

            return null;
        }

        return ['event_id' => (string) $data['event_id'], 'pass' => $pass];
    }

    /** Nunca lanza: cualquier fallo de red o parseo devuelve []. */
    private function fetchJson(string $url): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)($url) ?? [];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\n",
                // Un 3xx en un endpoint JSON es un problema de routing, no algo
                // a seguir: siguiéndolo, un loop de CDN se come todo el canje.
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function queryString(array $query): string
    {
        return $query === [] ? '' : '?' . http_build_query($query);
    }

    private static function queryWithoutToken(array $query): string
    {
        unset($query['vq_token'], $query['token']);

        return self::queryString($query);
    }
}
