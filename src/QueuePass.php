<?php

declare(strict_types=1);

namespace VQueue\Connector;

/**
 * Verificación del pase de cola (QueuePass) emitido por VQueue.
 *
 * Formato, idéntico a VQueue.Lines.QueuePass (vqueue/lib/v_queue/lines/queue_pass.ex)
 * y al core JS:
 *
 *   base64url(json_payload) "." base64url(hmac_sha256(private_key, base64url(json_payload)))
 *
 * Ambas partes SIN padding. El detalle fácil de errar: lo que se firma es el
 * payload YA codificado en base64url, no el JSON crudo.
 *
 * Payload: t (line id), e (event id), iat, exp — epoch en segundos.
 *
 * El secreto es el private_key de la compañía, así que la verificación es
 * OFFLINE: no hay que llamar a VQueue en cada request. Esa es toda la premisa
 * del conector.
 */
final class QueuePass
{
    public const COOKIE_PREFIX = 'vq_pass_';

    /** Nombre de la cookie del pase para un evento (es POR evento, no global). */
    public static function cookieName(string $eventId): string
    {
        return self::COOKIE_PREFIX . $eventId;
    }

    /**
     * @return array{ok: true, payload: array}|array{ok: false, reason: string}
     *         reason: malformed | bad_signature | expired
     */
    public static function verify(mixed $pass, string $secret, ?int $now = null): array
    {
        $now ??= time();

        if (!is_string($pass) || $pass === '' || $secret === '') {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $dot = strpos($pass, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($pass) - 1) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $encoded = substr($pass, 0, $dot);
        $signature = substr($pass, $dot + 1);

        // Firma primero: no se decodifica nada que no esté autenticado.
        // hash_equals compara en tiempo constante.
        if (!hash_equals(self::sign($encoded, $secret), $signature)) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        $json = self::base64UrlDecode($encoded);
        if ($json === null) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['exp']) || !is_int($payload['exp'])) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        if ($payload['exp'] <= $now) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * ¿Hay un pase válido para este evento entre las cookies?
     * Exige que el `e` del payload sea el evento consultado: un pase de otro
     * evento, aunque esté bien firmado, no sirve.
     *
     * @param array<string, string> $cookies
     */
    public static function validFor(array $cookies, string $eventId, string $secret, ?int $now = null): array
    {
        $raw = $cookies[self::cookieName($eventId)] ?? null;
        if ($raw === null) {
            return ['ok' => false, 'reason' => 'absent'];
        }

        $result = self::verify($raw, $secret, $now);
        if (!$result['ok']) {
            return $result;
        }

        if ((string) ($result['payload']['e'] ?? '') !== $eventId) {
            return ['ok' => false, 'reason' => 'event_mismatch'];
        }

        return $result;
    }

    private static function sign(string $encodedPayload, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $secret, true));
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        // strict: rechaza caracteres fuera del alfabeto en vez de ignorarlos.
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
