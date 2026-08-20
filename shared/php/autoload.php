<?php
/**
 * Minimal PSR-4 autoloader for the shared Imel library.
 * Applications may register extra prefixes with Imel\autoload_prefix().
 */

namespace Imel;

$GLOBALS['IMEL_AUTOLOAD_PREFIXES'] = ['Imel\\Shared\\' => __DIR__ . '/src/'];

function autoload_prefix(string $prefix, string $dir): void
{
    $GLOBALS['IMEL_AUTOLOAD_PREFIXES'][$prefix] = rtrim($dir, '/\\') . '/';
}

spl_autoload_register(function (string $class): void {
    foreach ($GLOBALS['IMEL_AUTOLOAD_PREFIXES'] as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
