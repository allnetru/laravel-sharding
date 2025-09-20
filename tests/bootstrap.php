<?php

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Allnetru\\Sharding\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
