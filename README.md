<p align="center"><img src="https://zanbug.com/logo.png" alt="Zanbug" width="120"></p>

# zanbug/yii2

Yii2 extension for the [Zanbug](https://zanbug.com) error & monitoring SDK.
It auto-captures unhandled exceptions by swapping in a Zanbug-aware
`errorHandler` component and initializes the framework-agnostic
`zanbug/php-sdk` core for you.

Compatible with `yiisoft/yii2` **>=2.0** and PHP **7.0+**.

## Install

```bash
composer require zanbug/yii2
```

## Configure — web app (`config/web.php`)

Register the bootstrap and swap the `errorHandler` component:

```php
return [
    // ...
    'bootstrap' => ['zanbug'],
    'components' => [
        'zanbug' => ['class' => \Zanbug\Yii2\Bootstrap::class],
        'errorHandler' => ['class' => \Zanbug\Yii2\WebErrorHandler::class],
    ],
    'params' => [
        'zanbug' => [
            'api_key' => 'bb_xxx',
            'host' => 'https://zanbug.com',
            'environment' => 'production',
            // 'capture_queries' => true,   // slow-query monitoring (default: on)
            // 'slow_query_ms' => 1000,     // report queries slower than this (ms)
        ],
    ],
];
```

**Slow query monitoring** — automatic: at the end of each request the
extension reads Yii's built-in DB profiling (`yii\db\Command::query` /
`::execute`, enabled by default via `Connection::$enableProfiling`) and
reports queries slower than `slow_query_ms` to Zanbug in one non-blocking
batch. Works with any Yii2 DB driver (MySQL, PostgreSQL, SQLite, ...). You
can also record manually:

```php
\Zanbug\Sdk\Zanbug::recordQuery($sql, $durationMs, ['connection' => 'mysql']);
```

## Configure — console app (`config/console.php`)

```php
return [
    // ...
    'bootstrap' => ['zanbug'],
    'components' => [
        'zanbug' => ['class' => \Zanbug\Yii2\Bootstrap::class],
        'errorHandler' => ['class' => \Zanbug\Yii2\ConsoleErrorHandler::class],
    ],
    'params' => [
        'zanbug' => [
            'api_key' => 'bb_xxx',
            'host' => 'https://zanbug.com',
            'environment' => 'production',
        ],
    ],
];
```

That's it — unhandled exceptions are now reported to Zanbug automatically.
The `zanbug` component reads its config from `params['zanbug']`; it no-ops if
`api_key` is empty.

## Manual capture

You can also report manually anywhere in your app:

```php
use Zanbug\Sdk\Zanbug;

try {
    // ...
} catch (\Throwable $e) {
    Zanbug::capture($e);
}

// Or a message:
Zanbug::captureMessage('Something noteworthy happened', 'warning');
```

## Configuration reference

| Key                | Type    | Default                 |
|--------------------|---------|-------------------------|
| `api_key`          | string  | `''`                    |
| `host`             | string  | `https://zanbug.com` |
| `environment`      | string  | `production`            |
| `release`          | string  | `null`                  |
| `enabled`          | bool    | `true`                  |
| `capture_requests` | bool    | `false`                 |
| `capture_queries`  | bool    | `true`                  |
| `slow_query_ms`    | int     | `1000`                  |
| `sample_rate`      | float   | `1.0`                   |
| `redact`           | array   | `[]`                    |
| `capture_logs`     | bool    | `false`                 |
| `log_level`        | string  | `error`                 |

## Updating the SDK
```bash
vendor/bin/zanbug check      # exit 10 when a newer version is published
vendor/bin/zanbug update     # composer require zanbug/php-sdk:^<latest> zanbug/yii2:^<latest>
```
Automatic: `ZANBUG_AUTO_UPDATE=true` (or `'auto_update' => true` in the SDK config) — once a day at the end of a CLI run the SDK updates itself in a detached process (log `<tmp>/zanbug-update.log`). Restart workers afterwards. Details: [zanbug/php-sdk README](https://github.com/zanbug/php-sdk#updating-the-sdk).

## Log capture (errors logged but not thrown)

Errors you catch and log without re-throwing only reach the log file. Enable
`capture_logs` and forward them to Zanbug with `recordLog()`:

```php
// In the component config (see above), add:
//   'capture_logs' => true,
//   'log_level'    => 'error',

// Anywhere you'd log an error (e.g. a Yii::error wrapper or directly):
\Zanbug\Sdk\Zanbug::recordLog('error', 'Queue job failed', ['job' => $id]);

// Caught-and-logged throwable (attach it for a full stacktrace):
try { risky(); } catch (\Throwable $e) {
    \Zanbug\Sdk\Zanbug::recordLog('error', $e->getMessage(), ['exception' => $e]);
}
```

Records below `log_level` are dropped; context is redacted; `recordLog()` never throws.
