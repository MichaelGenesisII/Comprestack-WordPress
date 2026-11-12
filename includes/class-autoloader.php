<?php

declare(strict_types=1);

final class Comprestack_Autoloader
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (str_starts_with($class, 'Comprestack\\')) {
                $relative = str_replace('\\', '/', substr($class, strlen('Comprestack\\')));
                $path = COMPRESTACK_PATH . 'includes/sdk/' . $relative . '.php';
                if (is_readable($path)) {
                    require_once $path;
                }
                return;
            }

            if (!str_starts_with($class, 'Comprestack_')) {
                return;
            }

            $map = [
                'Comprestack_Plugin' => 'class-plugin.php',
                'Comprestack_Settings' => 'class-settings.php',
                'Comprestack_Optimizer' => 'class-optimizer.php',
                'Comprestack_Media' => 'class-media.php',
                'Comprestack_Bulk' => 'class-bulk.php',
                'Comprestack_Admin_Notices' => 'class-admin-notices.php',
                'Comprestack_WooCommerce' => 'class-woocommerce.php',
            ];

            if (!isset($map[$class])) {
                return;
            }

            $path = COMPRESTACK_PATH . 'includes/' . $map[$class];
            if (is_readable($path)) {
                require_once $path;
            }
        });
    }
}
