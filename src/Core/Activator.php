<?php
/**
 * فعال‌سازی و غیرفعال‌سازی افزونه.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class Activator
{
	public static function activate(): void
	{
		$defaults = [
			'enabled'           => false,
			'capture_queries'   => true,
			'capture_hooks'     => true,
			'capture_network'   => true,
			'slow_query_ms'     => 20.0,
			'ai_provider'       => 'openai',
			'ai_model'          => 'gpt-4o',
			'ai_api_key'        => '',
			'ai_base_url'       => '',
			'dom_warn_elements' => 800,
		];

		if (false === get_option('speedpulse_settings')) {
			add_option('speedpulse_settings', $defaults, '', false);
		}

		if (false === get_option('speedpulse_crawler_state')) {
			add_option(
				'speedpulse_crawler_state',
				[
					'status'  => 'idle',
					'offset'  => 0,
					'total'   => 0,
					'results' => [],
					'updated' => time(),
				],
				'',
				false
			);
		}

		self::ensureMuBootstrap();
		self::syncEnabledFlag(false);
		flush_rewrite_rules();
	}

	public static function deactivate(): void
	{
		$settings            = get_option('speedpulse_settings', []);
		$settings['enabled'] = false;
		update_option('speedpulse_settings', $settings, false);
		self::syncEnabledFlag(false);
		wp_clear_scheduled_hook('speedpulse_crawler_tick');
	}

	/**
	 * همگام‌سازی پرچم فایل برای MU بدون وابستگی به دیتابیس.
	 */
	public static function syncEnabledFlag(bool $enabled): void
	{
		$flag = trailingslashit(WP_CONTENT_DIR) . 'speedpulse-enabled.flag';
		if ($enabled) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents($flag, '1');
		} elseif (file_exists($flag)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
			@unlink($flag);
		}
	}

	/**
	 * نصب فایل MU برای شروع زمان‌سنجی از اولین لحظه بارگذاری وردپرس.
	 */
	private static function ensureMuBootstrap(): void
	{
		$muDir = WPMU_PLUGIN_DIR;
		if (! is_dir($muDir)) {
			wp_mkdir_p($muDir);
		}

		$source = SPEEDPULSE_PATH . 'mu-bootstrap/speedpulse-early.php';
		$target = trailingslashit($muDir) . 'speedpulse-early.php';

		if (is_readable($source) && (! file_exists($target) || ! is_writable($target) || md5_file($source) !== md5_file($target))) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			@copy($source, $target);
		}
	}
}
