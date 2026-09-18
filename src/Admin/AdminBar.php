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
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
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
			'speedpulse-html2canvas',
			SPEEDPULSE_URL . 'assets/js/vendor/html2canvas.min.js',
			[],
			'1.4.1',
			true
		);
		wp_enqueue_script(
			'speedpulse-admin',
			SPEEDPULSE_URL . 'assets/js/admin-panel.js',
			['speedpulse-html2canvas'],
			SPEEDPULSE_VERSION,
			true
		);

		$enabled = $this->profiler->isEnabled();
		if ($enabled) {
			wp_enqueue_script(
				'speedpulse-browser',
				SPEEDPULSE_URL . 'assets/js/browser-collector.js',
				['speedpulse-admin'],
				SPEEDPULSE_VERSION,
				true
			);
		}

		$snap = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap) && $enabled) {
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

		$browser = get_transient('speedpulse_browser_metrics');

		wp_localize_script(
			'speedpulse-admin',
			'SpeedPulseData',
			[
				'ajaxUrl'        => admin_url('admin-ajax.php'),
				'nonce'          => wp_create_nonce('speedpulse_ajax'),
				'snapshot'       => $snap,
				'browserMetrics' => is_array($browser) ? $browser : null,
				'collectBrowser' => $enabled,
				'i18n'           => [
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

		$breakdown = is_array($snap['time_breakdown'] ?? null) ? $snap['time_breakdown'] : [];
		$serverHuman = (string) ($breakdown['total_human'] ?? ($snap['total_human'] ?? \SpeedPulsePro\Core\Profiler::formatDuration((float) ($snap['ttfb_ms'] ?? 0))));

		$browser = get_transient('speedpulse_browser_metrics');
		$browserHuman = '';
		if (is_array($browser) && ! empty($browser['browser_wall_human'])) {
			$browserHuman = (string) $browser['browser_wall_human'];
		}

		// اولویت نمایش: زمان واقعی مرورگر (شامل Network) اگر موجود باشد
		$primary = $browserHuman !== '' ? $browserHuman : $serverHuman;
		$primaryLabel = $browserHuman !== '' ? 'مرورگر' : 'سرور';

		$title = sprintf(
			'⏱ %s: %s%s | 🧠 رم: %s MB | %s',
			esc_html($primaryLabel),
			esc_html($primary),
			$browserHuman !== '' ? ' | سرور: ' . esc_html($serverHuman) : '',
			esc_html((string) ($snap['memory_mb'] ?? 0)),
			$status
		);

		$tip = $browserHuman !== ''
			? 'زمان مرورگر شامل همه درخواست‌های Network است. زمان سرور فقط تولید HTML همان صفحه است.'
			: (is_string($breakdown['summary_fa'] ?? null) ? (string) $breakdown['summary_fa'] : 'باز کردن پنل اسپید‌پالس پرو');

		$bar->add_node(
			[
				'id'    => 'speedpulse-pro',
				'title' => '<span class="speedpulse-ab-title">' . $title . '</span>',
				'href'  => '#speedpulse-drawer',
				'meta'  => [
					'class' => 'speedpulse-adminbar' . ($enabled ? ' is-live' : ''),
					'title' => $tip,
				],
			]
		);

		if ($browserHuman !== '') {
			$bar->add_node(
				[
					'id'     => 'speedpulse-browser-time',
					'parent' => 'speedpulse-pro',
					'title'  => 'زمان واقعی مرورگر (Network): ' . esc_html($browserHuman),
					'href'   => '#speedpulse-drawer',
				]
			);
			$bar->add_node(
				[
					'id'     => 'speedpulse-server-time',
					'parent' => 'speedpulse-pro',
					'title'  => 'زمان سرور (HTML): ' . esc_html($serverHuman),
					'href'   => '#speedpulse-drawer',
				]
			);
		}

		// زیر‌منو: جزئیات زمان سرور
		if (! empty($breakdown['items']) && is_array($breakdown['items'])) {
			foreach ($breakdown['items'] as $i => $item) {
				$bar->add_node(
					[
						'id'     => 'speedpulse-bd-' . (string) ($item['key'] ?? $i),
						'parent' => 'speedpulse-pro',
						'title'  => sprintf(
							'%s: %s (%s٪)%s',
							esc_html((string) ($item['label'] ?? '')),
							esc_html((string) ($item['human'] ?? '')),
							esc_html((string) ($item['percent'] ?? '0')),
							! empty($item['count']) ? ' — ' . (int) $item['count'] . ' مورد' : ''
						),
						'href'   => '#speedpulse-drawer',
					]
				);
			}
		}

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
