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

		$breakdown = is_array($snap['time_breakdown'] ?? null) ? $snap['time_breakdown'] : [];
		$totalHuman = (string) ($breakdown['total_human'] ?? ($snap['total_human'] ?? ((string) ($snap['ttfb_ms'] ?? 0) . ' ms')));
		$queryItem  = null;
		foreach (($breakdown['items'] ?? []) as $item) {
			if (($item['key'] ?? '') === 'queries') {
				$queryItem = $item;
				break;
			}
		}
		$queryHuman = is_array($queryItem) ? (string) ($queryItem['human'] ?? '—') : '—';
		$queryPct   = is_array($queryItem) ? (string) ($queryItem['percent'] ?? '0') : '0';

		$title = sprintf(
			'⏱ کل: %s | 🗄 کوئری: %s (%s٪) | 🧠 رم: %s مگابایت | 🔌 کندترین: %s | %s',
			esc_html($totalHuman),
			esc_html($queryHuman),
			esc_html($queryPct),
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
					'title' => is_string($breakdown['summary_fa'] ?? null)
						? (string) $breakdown['summary_fa']
						: 'باز کردن پنل اسپید‌پالس پرو',
				],
			]
		);

		// زیر‌منو: جزئیات زمان
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
