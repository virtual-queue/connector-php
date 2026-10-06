<?php

declare(strict_types=1);

namespace VQueue\Connector;

/**
 * Matcheo de ACLs. Misma semántica que el core JS, los Workers y el JS adapter:
 * reglas habilitadas, orden por prioridad ascendente, gana el primer match.
 *
 * La forma de cada regla la fija el admin (EdgeAcls::$public_fields):
 *   action, pattern, pattern_type, event_id, priority, enabled
 */
final class Acl
{
    /**
     * `.json` queda FUERA a propósito: lo usan endpoints dinámicos
     * (/products.json, /cart.json) que son justamente los que hay que proteger.
     * Debe mantenerse en sintonía con las demás integraciones.
     */
    public const ASSET_PATTERN = '/\.(css|js|mjs|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|eot|map)$/i';

    public static function isAsset(string $path): bool
    {
        return preg_match(self::ASSET_PATTERN, $path) === 1;
    }

    public static function matches(array $rule, string $path): bool
    {
        $pattern = $rule['pattern'] ?? null;

        // Un pattern vacío no matchea nada. str_starts_with($path, '') es true
        // para cualquier path, y una regla mal cargada no puede encolar el sitio
        // entero por accidente.
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        return match ($rule['pattern_type'] ?? null) {
            'prefix' => str_starts_with($path, $pattern),
            'exact' => $pattern === $path,
            'contains' => str_contains($path, $pattern),
            'glob' => self::globMatches($pattern, $path),
            default => false,
        };
    }

    /**
     * Ordena una vez por refresco de settings, no por request.
     *
     * Una regla sin `priority` numérica iría al final: dejarla con peso
     * indefinido haría que el orden —y con él, cuál regla gana— dependa del
     * algoritmo de sort.
     *
     * @param array<int, array> $rules
     * @return array<int, array>
     */
    public static function sort(array $rules): array
    {
        $enabled = array_values(array_filter($rules, static fn ($r) => is_array($r) && !empty($r['enabled'])));

        usort($enabled, static function (array $a, array $b): int {
            return self::priorityOf($a) <=> self::priorityOf($b);
        });

        return $enabled;
    }

    /** @param array<int, array> $sortedRules */
    public static function firstMatch(array $sortedRules, string $path): ?array
    {
        foreach ($sortedRules as $rule) {
            if (self::matches($rule, $path)) {
                return $rule;
            }
        }

        return null;
    }

    private static function priorityOf(array $rule): int|float
    {
        $priority = $rule['priority'] ?? null;

        return is_int($priority) || is_float($priority) ? $priority : PHP_INT_MAX;
    }

    private static function globMatches(string $pattern, string $path): bool
    {
        // Se escapa todo y después se reabre solo `*`: un `.` o un `(` en el
        // patrón no deben comportarse como metacaracteres de regex.
        $quoted = preg_quote($pattern, '/');
        $regex = '/^' . str_replace('\*', '.*', $quoted) . '$/';

        return preg_match($regex, $path) === 1;
    }
}
