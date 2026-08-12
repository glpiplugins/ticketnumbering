<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$coreVendor = dirname($pluginRoot) . '/glpi-core/vendor/autoload.php';
if (is_file($coreVendor)) {
    require_once $coreVendor;
}

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $prefix = 'GlpiPlugin\\Ticketnumbering\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $pluginRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
