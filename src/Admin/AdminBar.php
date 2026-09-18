<?php
/**
 * نوار مشکی ادمین — خلاصه زنده مانیتورینگ.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

use SpeedPulsePro\Core\Profiler;

final class AdminBar
{
	public function __construct(private Profiler $profiler)
	{
	}

	public function boot(): void
	{
		add_action('admin_bar_menu', [$this, 'render'], 100);
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('wp_enqueue_scripts', [$this, 'assets']);
	}

	public function assets(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		wp_enqueue_style(
			'speedpulse-admin',
			SPEEDPULSE_URL . 'assets/css/admin-rtl.css',
			[],
			SPEEDPULSE_VERSION
		);
		wp_enqueue_script(
			'speedpulse-admin',
			SPEEDPULSE_URL . 'assets/js/admin-panel.js',
			[],
			SPEEDPULSE_VERSION,
			true
		);

		$snap = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap) && $this->profiler->isEnabled()) {
			$snap = $this->profiler->snapshot();
		}
		if (! is_array($snap)) {
			$snap = [
				'ttfb_ms'        => 0,
				'query_count'    => 0,
				'memory_mb'      => 0,
				'slowest_source' => '—',
				'enabled'        => false,
			];
		}

		wp_localize_script(
			'speedpulse-admin',
			'SpeedPulseData',
			[
				'ajaxUrl'  => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('speedpulse_ajax'),
				'snapshot' => $snap,
				'i18n'     => [
					'recording' => 'ضبط زنده روشن',
					'stopped'   => 'ضبط خاموش',
					'loading'   => 'در حال بارگذاری…',
					'error'     => 'خطا در ارتباط',
					'saved'     => 'ذخیره شد',
				],
			]
		);
	}

	public function render(\WP_Admin_Bar $bar): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$snap = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap)) {
			$snap = [
				'ttfb_ms'        => 0,
				'query_count'    => 0,
				'memory_mb'      => 0,
				'slowest_source' => '—',
				'enabled'        => $this->profiler->isEnabled(),
			];
		}

		$enabled = ! empty($snap['enabled']) || $this->profiler->isEnabled();
		$status  = $enabled ? '● ضبط زنده' : '○ خاموش';
		$title   = sprintf(
			'⏱ TTFB: %s میلی‌ثانیه | 🗄 کوئری‌ها: %s عدد | 🧠 رم: %s مگابایت | 🔌 کندترین منبع: %s | %s',
			esc_html((string) ($snap['ttfb_ms'] ?? 0)),
			esc_html((string) ($snap['query_count'] ?? 0)),
			esc_html((string) ($snap['memory_mb'] ?? 0)),
			esc_html((string) ($snap['slowest_source'] ?? '—')),
			$status
		);

		$bar->add_node(
			[
				'id'    => 'speedpulse-pro',
				'title' => '<span class="speedpulse-ab-title">' . $title . '</span>',
				'href'  => '#speedpulse-drawer',
				'meta'  => [
					'class' => 'speedpulse-adminbar' . ($enabled ? ' is-live' : ''),
					'title' => 'باز کردن پنل اسپید‌پالس پرو',
				],
			]
		);

		$bar->add_node(
			[
				'id'     => 'speedpulse-toggle',
				'parent' => 'speedpulse-pro',
				'title'  => $enabled ? 'خاموش کردن مانیتورینگ' : 'روشن کردن مانیتورینگ',
				'href'   => '#',
				'meta'   => ['class' => 'speedpulse-toggle-recording'],
			]
		);
	}
}
