<?php

namespace Zanbug\Yii2;

use Zanbug\Sdk\Zanbug;
use yii\base\BootstrapInterface;

/**
 * Zanbug Yii2 bootstrap. Register in config/web.php (or console.php):
 *
 *     'bootstrap' => ['zanbug'],
 *     'components' => [
 *         'zanbug' => ['class' => \Zanbug\Yii2\Bootstrap::class],
 *     ],
 *     'params' => ['zanbug' => ['api_key' => 'bb_xxx']],
 *
 * Reads its config from $app->params['zanbug'] and initializes the
 * framework-agnostic zanbug/php-sdk core.
 */
class Bootstrap implements BootstrapInterface
{
    /** Package version, reported in the SDK ping (keep in step with the core's Zanbug::VERSION). */
    const VERSION = '2.0.1';

    /**
     * @param \yii\base\Application $app
     * @return void
     */
    public function bootstrap($app)
    {
        $params = isset($app->params['zanbug']) ? $app->params['zanbug'] : array();
        if (!is_array($params)) {
            return;
        }

        $apiKey = isset($params['api_key']) ? $params['api_key'] : '';
        if ($apiKey === '' || $apiKey === null) {
            return; // no-op when no API key is configured
        }

        // Metadata for the one-time install ping (SDK handshake).
        if (!isset($params['framework'])) {
            $params['framework'] = 'yii2';
        }
        if (!isset($params['framework_version'])) {
            try {
                if (class_exists('Yii') && method_exists('Yii', 'getVersion')) {
                    $params['framework_version'] = \Yii::getVersion();
                }
            } catch (\Exception $e) {
                // ignore
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if (!isset($params['app_name'])) {
            try {
                if (isset($app->name) && is_string($app->name) && $app->name !== '') {
                    $params['app_name'] = $app->name;
                }
            } catch (\Exception $e) {
                // ignore
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if (!isset($params['sdk'])) {
            $params['sdk'] = 'zanbug/yii2';
        }
        if ($params['sdk'] === 'zanbug/yii2' && !isset($params['sdk_version'])) {
            $params['sdk_version'] = self::VERSION;
        }

        Zanbug::init($params);
        $this->registerQueryCapture($app, $params);
        $this->registerQueryRunner($app);
        $this->registerRunAttribution($app);
        $this->registerUserResolver($app);
    }

    /**
     * Attach the logged-in user to every event without a manual setUser().
     * Covers the default "user" component and any other yii\web\User
     * component (e.g. a separate "admin" identity in the same app).
     *
     * @param \yii\base\Application $app
     * @return void
     */
    private function registerUserResolver($app)
    {
        try {
            if (!method_exists('\\Zanbug\\Sdk\\Zanbug', 'setUserResolver')) {
                return;
            }
            Zanbug::setUserResolver(function () use ($app) {
                return Bootstrap::resolveUser($app);
            });
        } catch (\Exception $e) {
            // Monitoring must never break the app.
        } catch (\Throwable $e) {
            // Same for engine errors.
        }
    }

    /**
     * @param \yii\base\Application $app
     * @return array|null
     */
    public static function resolveUser($app)
    {
        return \Zanbug\Sdk\Support\YiiAuth::forApp($app);
    }

    /**
     * Per-run attribution for console commands and yii2-queue jobs: the core
     * records one run per process (duration, CPU, memory, query time) and
     * needs the command / job class name to label it. Everything is guarded —
     * an older core without these methods is simply left alone.
     *
     * @param \yii\base\Application $app
     * @return void
     */
    private function registerRunAttribution($app)
    {
        try {
            if (!is_object($app) || !method_exists($app, 'on')) {
                return;
            }
            if (!method_exists('\\Zanbug\\Sdk\\Zanbug', 'setCommand')) {
                return;
            }
            if (class_exists('yii\\console\\Application', false) && $app instanceof \yii\console\Application) {
                $app->on(\yii\base\Controller::EVENT_BEFORE_ACTION, function ($event) {
                    try {
                        if (isset($event->action) && method_exists($event->action, 'getUniqueId')) {
                            Zanbug::setCommand((string) $event->action->getUniqueId());
                        }
                    } catch (\Exception $e) {
                        // ignore
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
            }
            // yii2-queue: one run per executed job, like a Laravel queue job.
            if (class_exists('yii\\queue\\Queue') && method_exists('\\Zanbug\\Sdk\\Zanbug', 'beginJob')) {
                \yii\base\Event::on('yii\\queue\\Queue', 'beforeExec', function ($event) {
                    try {
                        $class = isset($event->job) && is_object($event->job) ? get_class($event->job) : 'yii2-queue job';
                        $meta = array('kind' => 'yii2-queue');
                        if (isset($event->attempt)) {
                            $meta['attempts'] = (int) $event->attempt;
                        }
                        Zanbug::beginJob($class, $meta);
                        Zanbug::setRunSource('queue');
                    } catch (\Exception $e) {
                        // ignore
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
                \yii\base\Event::on('yii\\queue\\Queue', 'afterExec', function ($event) {
                    try {
                        Zanbug::endJob(0);
                    } catch (\Exception $e) {
                        // ignore
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
                \yii\base\Event::on('yii\\queue\\Queue', 'afterError', function ($event) {
                    try {
                        $err = null;
                        if (isset($event->error) && is_object($event->error)) {
                            $err = get_class($event->error) . ': ' . $event->error->getMessage();
                        }
                        Zanbug::endJob(1, $err);
                    } catch (\Exception $e) {
                        // ignore
                    } catch (\Throwable $e) {
                        // ignore
                    }
                });
            }
        } catch (\Exception $e) {
            // Monitoring must never break the app.
        } catch (\Throwable $e) {
            // Same for engine errors.
        }
    }

    /**
     * Let the Zanbug panel re-run one of this app's own captured SELECTs and
     * report the timing, so a developer can confirm an index actually helped.
     * Runs on Yii's own connection inside a transaction that is always rolled
     * back, and returns only the row COUNT — no row data ever leaves here.
     *
     * @param \yii\base\Application $app
     * @return void
     */
    private function registerQueryRunner($app)
    {
        try {
            if (!is_object($app) || !isset($app->db)) {
                return;
            }
            $db = $app->db;
            // The core SDK may be older than this adapter (stale lock file or a
            // manual libs/ copy loaded first). Never call into it blindly.
            if (!method_exists('\\Zanbug\\Sdk\\Zanbug', 'setQueryRunner')) {
                return;
            }

            Zanbug::setQueryRunner(function ($sql, array $bindings, $returnRows = false) use ($db) {
                $transaction = $db->beginTransaction();
                try {
                    $rows = $db->createCommand($sql, $bindings)->queryAll();
                    if (!is_array($rows)) {
                        return $returnRows ? array() : 0;
                    }

                    return $returnRows ? $rows : count($rows);
                } catch (\Exception $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw $e;
                } finally {
                    try {
                        $transaction->rollBack();
                    } catch (\Exception $e) {
                        // Nothing was written; a failed rollback is not fatal.
                    }
                }
            });
        } catch (\Exception $e) {
            // Monitoring must never break the app.
        } catch (\Throwable $e) {
            // Same for engine errors.
        }
    }

    /**
     * Slow-query capture: read Yii's built-in DB profiling at the end of the
     * request (EVENT_AFTER_REQUEST fires before the logger flushes, so the
     * profiling messages are still in memory). Durations are seconds -> ms;
     * the SDK drops anything faster than the configured slow_query_ms.
     * Fully guarded — never throws, never breaks the host app.
     *
     * @param \yii\base\Application $app
     * @param array $params
     * @return void
     */
    private function registerQueryCapture($app, array $params)
    {
        try {
            if (isset($params['capture_queries']) && !$params['capture_queries']) {
                return;
            }
            if (!is_object($app) || !method_exists($app, 'on')) {
                return;
            }
            $app->on(\yii\base\Application::EVENT_AFTER_REQUEST, function () {
                try {
                    if (!class_exists('Yii', false)) {
                        return;
                    }
                    $logger = \Yii::getLogger();
                    if (!is_object($logger) || !method_exists($logger, 'getProfiling')) {
                        return;
                    }
                    $profiling = $logger->getProfiling(array('yii\db\Command::query', 'yii\db\Command::execute'));
                    if (!is_array($profiling)) {
                        return;
                    }
                    // Driver name (mysql/pgsql) as "connection" — same value the server
                    // agent sends for slow-log rows, so both witnesses group as one query.
                    $connection = null;
                    if (isset(\Yii::$app) && is_object(\Yii::$app) && \Yii::$app->has('db', true)) {
                        $db = \Yii::$app->get('db', false);
                        if (is_object($db) && isset($db->driverName) && is_string($db->driverName) && $db->driverName !== '') {
                            $connection = $db->driverName;
                        }
                    }
                    foreach ($profiling as $entry) {
                        if (!isset($entry['info'], $entry['duration'])) {
                            continue;
                        }
                        $meta = array();
                        if ($connection !== null) {
                            $meta['connection'] = $connection;
                        }
                        // Logger traces (when traceLevel > 0) give us the app caller.
                        if (isset($entry['trace'][0]['file']) && is_string($entry['trace'][0]['file'])) {
                            $meta['file'] = $entry['trace'][0]['file'];
                            if (isset($entry['trace'][0]['line'])) {
                                $meta['line'] = $entry['trace'][0]['line'];
                            }
                        }
                        Zanbug::recordQuery((string) $entry['info'], ((float) $entry['duration']) * 1000, $meta);
                    }
                } catch (\Exception $e) {
                    // never break the host app
                } catch (\Throwable $e) {
                    // non-fatal
                }
            });
        } catch (\Exception $e) {
            // never break the host app
        } catch (\Throwable $e) {
            // non-fatal
        }
    }
}
