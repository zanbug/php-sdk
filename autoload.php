<?php

/**
 * Manual (Composer-free) autoloader for legacy projects.
 *
 * Usage in any old PHP project that cannot run Composer:
 *
 *     require __DIR__ . '/path/to/zanbug-php-sdk/autoload.php';
 *     \Zanbug\Sdk\Zanbug::init(array('api_key' => 'bb_xxx', 'host' => 'https://zanbug.com'));
 *     \Zanbug\Sdk\Zanbug::registerHandlers(); // automatic error/exception capture
 */

spl_autoload_register(function ($class) {
    $prefix = 'Zanbug\\Sdk\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/src/helpers.php';
