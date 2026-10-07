<?php

namespace Zanbug\Sdk\Support;

/**
 * Finds the application code that triggered an SDK call: the first stack
 * frame outside vendor/ and outside the SDK itself. Used to attribute slow
 * queries to the file/line that ran them. NEVER throws.
 */
class CallerFinder
{
    /**
     * @return array|null array('file' => string, 'line' => int|null) or null when not found.
     */
    public static function find()
    {
        try {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
            // The SDK's own src/ directory (also covers manual, non-Composer installs).
            $sdkDir = str_replace('\\', '/', dirname(dirname(__FILE__)));

            foreach ($frames as $frame) {
                if (empty($frame['file']) || !is_string($frame['file'])) {
                    continue;
                }
                $file = str_replace('\\', '/', $frame['file']);
                if (strpos($file, $sdkDir . '/') === 0) {
                    continue; // inside the SDK
                }
                if (strpos($file, '/vendor/') !== false) {
                    continue; // inside Composer dependencies (frameworks, adapters...)
                }
                if (preg_match('~/(zanbug|bugban)-(php-sdk|laravel|symfony|codeigniter|yii2)[^/]*/src/~', $file)) { // legacy: bugban → zanbug (2026-10-07)
                    continue; // a manually installed adapter (libs/zanbug-laravel/src/...)
                }
                return array(
                    'file' => $frame['file'],
                    'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                );
            }
        } catch (\Exception $e) {
            // never break the host app
        } catch (\Throwable $e) {
            // non-fatal
        }

        return null;
    }
}
