<?php

declare(strict_types=1);

/*
 * Uses the plugin's own vendor/ when installed standalone, or the autoloader of a Sylius
 * application given by SKEEPERS_AUTOLOAD (the plugin installed in its vendor/):
 *   SKEEPERS_AUTOLOAD=/path/to/shop/vendor/autoload.php vendor/bin/phpunit
 */
$autoload = getenv('SKEEPERS_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Altmenorg\\SyliusSkeepersPlugin\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        foreach ([__DIR__ . '/../src/', __DIR__ . '/../'] as $root) {
            $file = $root . (str_starts_with($relative, 'Tests/') ? 'tests/' . substr($relative, 6) : $relative) . '.php';
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
});
