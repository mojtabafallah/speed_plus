<?php
/**
 * پروفایلر سبک هوک‌ها — فقط هوک‌های کلیدی با نمونه‌برداری زمانی.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class HookProfiler
{
	/** @var array<string, float> */
	private array $starts = [];

	public function __construct(private Profiler $profiler)
	{
	}

	public function boot(): void
	{
		$hooks = [
			'plugins_loaded',
			'setup_theme',
			'after_setup_theme',
			'init',
			'wp_loaded',
			'parse_request',
			'pre_get_posts',
			'wp',
			'template_redirect',
			'wp_enqueue_scripts',
			'wp_head',
			'wp_footer',
			'admin_init',
			'admin_enqueue_scripts',
			'woocommerce_init',
			'woocommerce_cart_loaded_from_session',
			'woocommerce_before_calculate_totals',
			'woocommerce_checkout_process',
			'shutdown',
		];

		foreach ($hooks as $hook) {
			add_action($hook, function () use ($hook): void {
				$this->starts[$hook] = microtime(true);
			}, PHP_INT_MIN);

			add_action($hook, function () use ($hook): void {
				$start = $this->starts[$hook] ?? microtime(true);
				$ms    = (microtime(true) - $start) * 1000;
				$this->profiler->addHook([
					'hook'     => $hook,
					'priority' => 0,
					'callback' => 'مجموع کالبک‌های هوک',
					'source'   => str_contains($hook, 'woocommerce') ? 'افزونه: woocommerce' : 'هسته وردپرس',
					'cpu_ms'   => round($ms, 3),
					'memory'   => 0,
					'id'       => $hook,
				]);
			}, PHP_INT_MAX);
		}
	}
}
