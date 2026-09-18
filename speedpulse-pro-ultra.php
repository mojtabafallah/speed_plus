<?php
/**
 * Plugin Name: SpeedPulse Pro Ultra
 * Plugin URI: https://github.com/mojtabafallah/speed_plus
 * Description: Real-time WordPress performance monitor (Persian RTL). Server timing, browser Network, queries, RAM/CPU, and tips.
 * Version: 1.0.15
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Mojtaba Fallah
 * Author URI: https://github.com/mojtabafallah
 * Text Domain: speedpulse-pro
 * Domain Path: /languages
 * GitHub Plugin URI: mojtabafallah/speed_plus
 *
 * نام فارسی: اسپید‌پالس پرو اولترا
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

if (version_compare(PHP_VERSION, '7.4', '<')) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html('SpeedPulse Pro requires PHP 7.4+. Current: ' . PHP_VERSION);
			echo '</p></div>';
		}
	);
	return;
}

define('SPEEDPULSE_VERSION', '1.0.15');
define('SPEEDPULSE_FILE', __FILE__);
define('SPEEDPULSE_PATH', plugin_dir_path(__FILE__));
define('SPEEDPULSE_URL', plugin_dir_url(__FILE__));
define('SPEEDPULSE_BASENAME', plugin_basename(__FILE__));
define('SPEEDPULSE_GITHUB', 'https://github.com/mojtabafallah/speed_plus');
define('SPEEDPULSE_AUTHOR', 'Mojtaba Fallah');
define('SPEEDPULSE_AUTHOR_URI', 'https://github.com/mojtabafallah');

require_once SPEEDPULSE_PATH . 'src/Compat.php';
require_once SPEEDPULSE_PATH . 'src/Autoloader.php';

\SpeedPulsePro\Autoloader::register(SPEEDPULSE_PATH . 'src/');

register_activation_hook(__FILE__, static function (): void {
	\SpeedPulsePro\Core\Activator::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
	\SpeedPulsePro\Core\Activator::deactivate();
});

add_action(
	'plugins_loaded',
	static function (): void {
		\SpeedPulsePro\Plugin::instance()->boot();
	},
	1
);
