<?php

namespace Zanbug\Yii2;

use Zanbug\Sdk\Zanbug;

/**
 * Drop-in replacement for Yii2's console error handler that forwards every
 * logged exception to Zanbug. Swap it into config/console.php:
 *
 *     'components' => [
 *         'errorHandler' => ['class' => \Zanbug\Yii2\ConsoleErrorHandler::class],
 *     ],
 */
class ConsoleErrorHandler extends \yii\console\ErrorHandler
{
    /**
     * @param \Throwable|\Exception $exception
     * @return void
     */
    public function logException($exception)
    {
        parent::logException($exception);

        try {
            // Reached Yii's error handler = nobody caught it → unhandled.
            // Guarded: an older core without captureUnhandled() degrades to capture().
            if (method_exists('Zanbug\\Sdk\\Zanbug', 'captureUnhandled')) {
                Zanbug::captureUnhandled($exception);
            } else {
                Zanbug::capture($exception);
            }
        } catch (\Exception $e) {
            // never let reporting break Yii's own error handling
        } catch (\Throwable $e) {
            // PHP 7+ fatal errors
        }
    }
}
