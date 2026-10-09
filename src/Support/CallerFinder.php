<?php

namespace Zanbug\Sdk\Support;

/**
 * Finds the application code that triggered an SDK call: the first stack
 * frame outside vendor/ and outside the SDK itself. Used to attribute slow
 * queries to the file/line that ran them. NEVER throws.
 */
class CallerFinder
{
    /** Max app frames kept in the call chain sent with a slow query. */
    const MAX_TRACE = 5;

    /**
     * @return array|null array('file' => string, 'line' => int|null) or null when not found.
     */
    public static function find()
    {
        $found = self::locate(0);

        return $found['caller'];
    }

    /**
     * The caller plus the app's call chain, from ONE backtrace.
     *
     * 'caller' = first app frame (see find()). 'trace' = up to $limit app
     * frames, innermost first, each array('file', 'line', 'fn') where fn is the
     * function the line belongs to (Class->method / Class::method / {closure}).
     * Frames of a framework-dispatched listener closure (an app's
     * DB::listen(...) that calls Zanbug::recordQuery) are left out of both:
     * they say where the SDK was called, not which code ran the query.
     *
     * @param int $limit
     * @return array array('caller' => array|null, 'trace' => array)
     */
    public static function locate($limit = self::MAX_TRACE)
    {
        $caller = null;
        $trace = array();
        try {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60);
            // The SDK's own src/ directory (also covers manual, non-Composer installs).
            $sdkDir = str_replace('\\', '/', dirname(dirname(__FILE__)));
            $listenerFrame = null;

            foreach ($frames as $i => $frame) {
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
                // The app's own DB::listen(function ($q) { Zanbug::recordQuery(...) })
                // closure: the frame is the listener, not the code that ran the
                // query. Keep walking to the real caller; fall back to the
                // listener only if no app frame is found further up.
                if (self::isFrameworkListener($frames, $i)) {
                    if ($listenerFrame === null) {
                        $listenerFrame = $frame;
                    }
                    continue;
                }
                if ($caller === null) {
                    $caller = array(
                        'file' => $frame['file'],
                        'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                    );
                }
                if (count($trace) >= $limit) {
                    break;
                }
                $trace[] = array(
                    'file' => $frame['file'],
                    'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                    'fn' => self::frameFunction(isset($frames[$i + 1]) ? $frames[$i + 1] : null),
                );
            }
            if ($caller === null && $listenerFrame !== null) {
                $caller = array(
                    'file' => $listenerFrame['file'],
                    'line' => isset($listenerFrame['line']) ? (int) $listenerFrame['line'] : null,
                );
            }
        } catch (\Exception $e) {
            // never break the host app
        } catch (\Throwable $e) {
            // non-fatal
        }

        return array('caller' => $caller, 'trace' => $trace);
    }

    /**
     * "Class->method" for the frame that CONTAINS a line (the next frame up the
     * backtrace); null at the top level of a script.
     *
     * @param array|null $frame
     * @return string|null
     */
    private static function frameFunction($frame)
    {
        if (!is_array($frame) || empty($frame['function']) || !is_string($frame['function'])) {
            return null;
        }
        $fn = $frame['function'];
        if (in_array($fn, array('include', 'include_once', 'require', 'require_once'), true)) {
            return null;
        }
        if (!empty($frame['class']) && is_string($frame['class'])) {
            $type = (isset($frame['type']) && is_string($frame['type'])) ? $frame['type'] : '::';
            $fn = $frame['class'] . $type . $fn;
        }

        return strlen($fn) > 160 ? substr($fn, 0, 160) : $fn;
    }

    /**
     * True when frame $i sits inside a closure that a framework event
     * dispatcher invoked (Laravel DB::listen / Event::listen, Symfony, Yii).
     */
    private static function isFrameworkListener(array $frames, $i)
    {
        if (!isset($frames[$i + 1]['function']) || strpos((string) $frames[$i + 1]['function'], '{closure') !== 0) {
            return false;
        }
        $from = isset($frames[$i + 1]['file']) ? str_replace('\\', '/', (string) $frames[$i + 1]['file']) : '';
        if ($from === '') {
            // closure called via call_user_func*: the dispatcher is one frame further
            $from = isset($frames[$i + 2]['file']) ? str_replace('\\', '/', (string) $frames[$i + 2]['file']) : '';
        }

        return (bool) preg_match('~/(Illuminate|illuminate)/(Events|Database|events|database)/|/symfony/event-dispatcher/|/yiisoft/yii2/base/Component\.php$~', $from);
    }
}
