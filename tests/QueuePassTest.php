<?php

declare(strict_types=1);

namespace VQueue\Connector\Tests;

use PHPUnit\Framework\TestCase;
use VQueue\Connector\QueuePass;

/**
 * VECTOR DE INTEROPERABILIDAD
 *
 * Este pase NO lo generó este código: lo firmó la implementación Elixir real
 * (VQueue.Lines.QueuePass.sign/4). Es el MISMO vector que usa el core JS, así que
 * las tres implementaciones quedan ancladas al mismo formato. Si alguna deriva,
 * este test se cae.
 *
 *   secret:  "test-private-key-abc123"
 *   iat/exp: 1700000000 / 1700003600
 *   evento:  "ev-42"
 */
final class QueuePassTest extends TestCase
{
    private const PASS = 'eyJlIjoiZXYtNDIiLCJleHAiOjE3MDAwMDM2MDAsImlhdCI6MTcwMDAwMDAwMCwidCI6IjExMTExMTExLTExMTEtMTExMS0xMTExLTExMTExMTExMTExMSJ9'
        . '.AjaVlbsXru7V8GOJuhEV2doxd3W1-dQlEVTi5BEnNco';

    private const SECRET = 'test-private-key-abc123';
    private const BEFORE_EXP = 1_700_000_100;
    private const AFTER_EXP = 1_700_003_601;

    public function test_acepta_un_pase_firmado_por_vqueue(): void
    {
        $result = QueuePass::verify(self::PASS, self::SECRET, self::BEFORE_EXP);

        self::assertTrue($result['ok']);
        self::assertSame('ev-42', $result['payload']['e']);
        self::assertSame('11111111-1111-1111-1111-111111111111', $result['payload']['t']);
        self::assertSame(1_700_003_600, $result['payload']['exp']);
    }

    public function test_lo_rechaza_cuando_vencio(): void
    {
        $result = QueuePass::verify(self::PASS, self::SECRET, self::AFTER_EXP);

        self::assertFalse($result['ok']);
        self::assertSame('expired', $result['reason']);
    }

    public function test_lo_rechaza_con_otro_secreto(): void
    {
        $result = QueuePass::verify(self::PASS, 'otro-secreto', self::BEFORE_EXP);

        self::assertSame('bad_signature', $result['reason']);
    }

    public function test_rechaza_un_payload_alterado(): void
    {
        [$encoded, $signature] = explode('.', self::PASS, 2);
        $payload = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true), true);

        // Extender el vencimiento 10 años: la firma deja de cerrar.
        $payload['exp'] = 2_000_000_000;
        $forged = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $result = QueuePass::verify($forged . '.' . $signature, self::SECRET, self::BEFORE_EXP);

        self::assertSame('bad_signature', $result['reason']);
    }

    public function test_rechaza_una_firma_recortada(): void
    {
        [$encoded, $signature] = explode('.', self::PASS, 2);
        $result = QueuePass::verify($encoded . '.' . substr($signature, 0, -1), self::SECRET, self::BEFORE_EXP);

        self::assertSame('bad_signature', $result['reason']);
    }

    /** @dataProvider formatosRotos */
    public function test_rechaza_formatos_rotos_sin_lanzar(mixed $bad): void
    {
        self::assertFalse(QueuePass::verify($bad, self::SECRET, self::BEFORE_EXP)['ok']);
    }

    public static function formatosRotos(): array
    {
        return [
            'vacío' => [''],
            'sin punto' => ['sin-punto'],
            'solo firma' => ['.solo-firma'],
            'solo payload' => ['solo-payload.'],
            'null' => [null],
            'número' => [42],
        ];
    }

    public function test_rechaza_un_payload_bien_firmado_pero_sin_exp(): void
    {
        $encoded = rtrim(strtr(base64_encode(json_encode(['t' => 'x', 'e' => 'ev-42'])), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, self::SECRET, true)), '+/', '-_'), '=');

        $result = QueuePass::verify($encoded . '.' . $signature, self::SECRET, self::BEFORE_EXP);

        self::assertSame('malformed', $result['reason']);
    }

    public function test_sin_secreto_no_valida_nada(): void
    {
        self::assertFalse(QueuePass::verify(self::PASS, '', self::BEFORE_EXP)['ok']);
    }

    public function test_acepta_el_pase_del_evento_consultado(): void
    {
        $cookies = [QueuePass::cookieName('ev-42') => self::PASS];

        self::assertTrue(QueuePass::validFor($cookies, 'ev-42', self::SECRET, self::BEFORE_EXP)['ok']);
    }

    public function test_no_admite_al_evento_b_con_el_pase_del_evento_a(): void
    {
        // La cookie del evento B contiene un pase válido... pero de otro evento.
        $cookies = [QueuePass::cookieName('ev-99') => self::PASS];

        $result = QueuePass::validFor($cookies, 'ev-99', self::SECRET, self::BEFORE_EXP);

        self::assertSame('event_mismatch', $result['reason']);
    }

    public function test_informa_cuando_no_hay_cookie(): void
    {
        self::assertSame('absent', QueuePass::validFor([], 'ev-42', self::SECRET)['reason']);
    }
}
