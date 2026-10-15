<?php

use Zanbug\Sdk\Zanbug;

/**
 * Procedural helpers for legacy / non-OO codebases.
 */

if (!function_exists('zanbug_init')) {
    function zanbug_init(array $config)
    {
        return Zanbug::init($config);
    }
}

if (!function_exists('zanbug_capture')) {
    /**
     * @param \Throwable|\Exception $e
     */
    function zanbug_capture($e, array $extra = array())
    {
        Zanbug::capture($e, $extra);
    }
}

if (!function_exists('zanbug_message')) {
    function zanbug_message($message, $level = 'info', array $extra = array())
    {
        Zanbug::captureMessage($message, $level, $extra);
    }
}

if (!function_exists('zanbug')) {
    /**
     * @return \Zanbug\Sdk\Client|null
     */
    function zanbug()
    {
        return Zanbug::client();
    }
}
