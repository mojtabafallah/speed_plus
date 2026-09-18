<?php
/**
 * Plugin Name: اسپید‌پالس پرو اولترا
 * Plugin URI:  https://github.com/mojtabafallah/speed_plus
 * Description: سامانه مانیتورینگ بلادرنگ، عیب‌یاب PHP، جراح ووکامرس و تولید پچ بهینه‌سازی مبتنی بر هوش مصنوعی — کاملاً فارسی و راست‌چین.
 * Version:     1.0.11
 * Author:      Mojtaba Fallah
 * Author URI:  https://github.com/mojtabafallah
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: speedpulse-pro
 * Domain Path: /languages
 * GitHub Plugin URI: mojtabafallah/speed_plus
 *
 * @package SpeedPulsePro
 * @author  Mojtaba Fallah <https://github.com/mojtabafallah>
 * @link    https://github.com/mojtabafallah/speed_plus
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('SPEEDPULSE_VERSION', '1.0.11');
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

add_action('plugins_loaded', static function (): void {
	\SpeedPulsePro\Plugin::instance()->boot();
}, 1);
