<?php
/**
 * Plugin Name: اسپید‌پالس پرو اولترا
 * Plugin URI:  https://speedpulse.local/
 * Description: سامانه مانیتورینگ بلادرنگ، عیب‌یاب PHP، جراح ووکامرس و تولید پچ بهینه‌سازی مبتنی بر هوش مصنوعی — کاملاً فارسی و راست‌چین.
 * Version:     1.0.0
 * Author:      SpeedPulse Labs
 * Requires at least: 6.4
 * Requires PHP: 8.3
 * Text Domain: speedpulse-pro
 * Domain Path: /languages
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('SPEEDPULSE_VERSION', '1.0.0');
define('SPEEDPULSE_FILE', __FILE__);
define('SPEEDPULSE_PATH', plugin_dir_path(__FILE__));
define('SPEEDPULSE_URL', plugin_dir_url(__FILE__));
define('SPEEDPULSE_BASENAME', plugin_basename(__FILE__));

require_once SPEEDPULSE_PATH . 'src/Autoloader.php';

\SpeedPulsePro\Autoloader::register(SPEEDPULSE_PATH . 'src/');

register_activation_hook(__FILE__, static function (): void {
	\SpeedPulsePro\Core\Activator::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
	\SpeedPulsePro\Core\Activator::deactivate();
});

add_action('plugins_loaded', static function (): void {
	\SpeedPulsePro\Plugin::instance()->boot();
}, 1);
