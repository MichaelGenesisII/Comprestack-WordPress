<?php
/**
 * Plugin Name:       Comprestack
 * Plugin URI:        https://www.comprestack.com/docs
 * Description:       Compress and convert Media Library images with the Comprestack API (WebP/AVIF and more).
 * Version:           0.1.3
 * WC requires at least: 8.0
 * WC tested up to:   9.6
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Comprestack
 * Author URI:        https://www.comprestack.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       comprestack
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('COMPRESTACK_VERSION', '0.1.3');
define('COMPRESTACK_FILE', __FILE__);
define('COMPRESTACK_PATH', plugin_dir_path(__FILE__));
define('COMPRESTACK_URL', plugin_dir_url(__FILE__));

require_once COMPRESTACK_PATH . 'includes/class-autoloader.php';
Comprestack_Autoloader::register();

require_once COMPRESTACK_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', static function (): void {
    Comprestack_Plugin::instance()->boot();
});
