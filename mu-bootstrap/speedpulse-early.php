<?php
/**
 * بوت‌استرپ زودهنگام MU — زمان شروع مطلق درخواست.
 * هنگام فعال‌سازی به wp-content/mu-plugins کپی می‌شود.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! defined('SPEEDPULSE_REQUEST_START')) {
	define('SPEEDPULSE_REQUEST_START', microtime(true));
}

if (! defined('SPEEDPULSE_REQUEST_MEM_START')) {
	define('SPEEDPULSE_REQUEST_MEM_START', memory_get_usage(true));
}

$GLOBALS['speedpulse_early'] = [
	'start'     => SPEEDPULSE_REQUEST_START,
	'mem_start' => SPEEDPULSE_REQUEST_MEM_START,
	'rusage'    => function_exists('getrusage') ? getrusage() : null,
];

/**
 * اگر پرچم ضبط فعال باشد، SAVEQUERIES را زود روشن می‌کنیم.
 * پرچم از فایل سبک بدون نیاز به دیتابیس خوانده می‌شود.
 */
$flag = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(__DIR__, 2)) . '/speedpulse-enabled.flag';
if (is_readable($flag)) {
	if (! defined('SAVEQUERIES')) {
		define('SAVEQUERIES', true);
	}
}
