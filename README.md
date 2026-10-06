# vqueue/connector (PHP)

Protección de cola **dentro de la app PHP del cliente**. Equivalente server-side
del JS adapter: el visitante no puede saltearla desactivando JavaScript.

## Uso

```php
use VQueue\Connector\Guard;

$guard = new Guard(
    client: 'orome',                        // subdominio de la compañía
    privateKey: getenv('VQUEUE_PRIVATE_KEY'), // nunca hardcodeada
);

$decision = $guard->decide([
    'path'    => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'query'   => $_GET,
    'cookies' => $_COOKIE,
    'method'  => $_SERVER['REQUEST_METHOD'],
]);

if ($decision['type'] === 'redirect') {
    foreach ($decision['cookies'] ?? [] as $c) {
        setcookie($c['name'], $c['value'], [
            'expires'  => time() + $c['maxAge'],
            'path'     => '/',
            'httponly' => true,
            'secure'   => true,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . $decision['location'], true, 302);
    header('Cache-Control: no-store');
    exit;
}

if (isset($decision['renew'])) {
    $r = $decision['renew'];
    setcookie($r['name'], $r['value'], [
        'expires' => time() + $r['maxAge'], 'path' => '/',
        'httponly' => true, 'secure' => true, 'samesite' => 'Lax',
    ]);
}
```

## El cache importa más acá que en las otras integraciones

En Workers, Lambda o Node hay un proceso vivo donde cachear los settings. En
PHP-FPM el proceso muere con el request, así que sin cache compartido **cada page
view pagaría un round trip a la API**.

Por defecto se usa APCu (compartido por el pool de FPM) y, si no está, un archivo
en el directorio temporal. Con Redis o Memcached, implementá `SettingsCache` e
inyectalo:

```php
$guard = new Guard(client: 'orome', privateKey: $key, cache: new MiCacheRedis());
```

Verificá que APCu esté habilitado (`apc.enabled=1`); el fallback a disco funciona
pero es más lento. En runtimes persistentes (Octane, RoadRunner, Swoole) sirve
`MemorySettingsCache`.

Si la API del admin no responde, se sigue sirviendo la última configuración
buena (hasta una hora) y se espera unos segundos antes de reintentar: una caída
del admin no deja el sitio sin protección ni le agrega el timeout a cada página.

El nombre del archivo del cache a disco sale de un hash que incluye la
`private_key`: en un hosting compartido, `/tmp` es escribible por todos y un
vecino que adivinara el nombre podría dejar ahí sus propios settings.

## Qué hace

1. **Bypass barato** — assets, `/api/`, WebSockets y métodos que no son GET/HEAD.
2. **Vuelta de la cola** — canjea `?vq_token=`, emite `vq_pass_<event_id>` y
   vuelve al destino original. Un `?token=` propio del sitio no se secuestra.
3. **ACLs** — por prioridad, primera gana.
4. **Pase** — verifica el HMAC **offline** con la `private_key`.
5. **Renovación deslizante** — mientras el visitante navegue, el pase se extiende.

Todo falla abierto.

## Tests

```bash
composer install && ./vendor/bin/phpunit
```
