# vqueue/connector (PHP)

Queue protection **inside your own PHP app**. It is the server-side equivalent of
the JS adapter: a visitor can't bypass it by disabling JavaScript.

## Usage

```php
use VQueue\Connector\Guard;

$guard = new Guard(
    client: 'orome',                          // your company subdomain
    privateKey: getenv('VQUEUE_PRIVATE_KEY'), // never hardcode it
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

## The cache matters more here than in the other integrations

In Workers, Lambda, or Node there is a long-lived process where settings can be
cached. In PHP-FPM the process dies with the request, so without a shared cache
**every page view would pay a round trip to the API**.

By default it uses APCu (shared by the FPM pool) and, if that isn't available, a
file in the temp directory. With Redis or Memcached, implement `SettingsCache` and
inject it:

```php
$guard = new Guard(client: 'orome', privateKey: $key, cache: new MyRedisCache());
```

Make sure APCu is enabled (`apc.enabled=1`); the disk fallback works but is slower.
In persistent runtimes (Octane, RoadRunner, Swoole), `MemorySettingsCache` works.

If the admin API stops responding, the last good configuration keeps being served
(for up to an hour) and the guard waits a few seconds before retrying: an admin
outage neither leaves your site unprotected nor adds a timeout to every page.

The disk cache file name comes from a hash that includes the `private_key`: on
shared hosting `/tmp` is writable by everyone, and a neighbor who guessed the name
could drop their own settings there.

## What it does

1. **Cheap bypass** — assets, `/api/`, WebSockets, and methods other than GET/HEAD.
2. **Return from the queue** — exchanges `?vq_token=`, issues `vq_pass_<event_id>`,
   and returns to the original destination. Your site's own `?token=` is never
   hijacked.
3. **ACLs** — by priority, first match wins.
4. **Pass** — verifies the HMAC **offline** with the `private_key`.
5. **Sliding renewal** — while the visitor keeps browsing, the pass is extended.

Everything fails open.

## Tests

```bash
composer install && ./vendor/bin/phpunit
```
