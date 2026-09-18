<?php
/**
 * حذف داده‌های افزونه هنگام uninstall.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('speedpulse_settings');
delete_option('speedpulse_crawler_state');
delete_option('speedpulse_patch_meta');
delete_transient('speedpulse_last_snapshot');
delete_transient('speedpulse_dom_last');
delete_transient('speedpulse_memory_report');
delete_transient('speedpulse_ai_last_analysis');
delete_transient('speedpulse_pending_patch');

$mu = trailingslashit(WPMU_PLUGIN_DIR) . 'speedpulse-early.php';
if (file_exists($mu)) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
	@unlink($mu);
}

$patch = trailingslashit(WP_CONTENT_DIR) . 'speedpulse-optimizer-patch.php';
if (file_exists($patch)) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
	@unlink($patch);
}

$flag = trailingslashit(WP_CONTENT_DIR) . 'speedpulse-enabled.flag';
if (file_exists($flag)) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
	@unlink($flag);
}
