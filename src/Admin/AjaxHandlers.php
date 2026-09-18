<?php
/**
 * هندلرهای AJAX پیشخوان.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

use SpeedPulsePro\AI\AiClient;
use SpeedPulsePro\AI\CanaryRunner;
use SpeedPulsePro\AI\PatchGenerator;
use SpeedPulsePro\Core\Profiler;
use SpeedPulsePro\Crawler\SiteCrawler;
use SpeedPulsePro\Cron\CronAuditor;
use SpeedPulsePro\Database\IndexOptimizer;
use SpeedPulsePro\Debug\LogStreamer;
use SpeedPulsePro\Stress\StressTester;
use SpeedPulsePro\WooCommerce\WooSurgeon;

final class AjaxHandlers
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		$actions = [
			'speedpulse_toggle'         => 'toggle',
			'speedpulse_snapshot'       => 'snapshot',
			'speedpulse_dom_report'     => 'domReport',
			'speedpulse_debug_log'      => 'debugLog',
			'speedpulse_clear_log'      => 'clearLog',
			'speedpulse_indexes'        => 'indexes',
			'speedpulse_apply_index'    => 'applyIndex',
			'speedpulse_woo_report'     => 'wooReport',
			'speedpulse_woo_cleanup'    => 'wooCleanup',
			'speedpulse_stress'         => 'stress',
			'speedpulse_crawler'        => 'crawler',
			'speedpulse_ai_analyze'     => 'aiAnalyze',
			'speedpulse_patch_generate' => 'patchGenerate',
			'speedpulse_patch_save'     => 'patchSave',
			'speedpulse_patch_canary'   => 'patchCanary',
			'speedpulse_patch_rollback' => 'patchRollback',
			'speedpulse_save_settings'  => 'saveSettings',
			'speedpulse_cron_inventory' => 'cronInventory',
			'speedpulse_memory_report'  => 'memoryReport',
		];

		foreach ($actions as $action => $method) {
			add_action('wp_ajax_' . $action, [$this, $method]);
		}
	}

	private function guard(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'دسترسی کافی ندارید.'], 403);
		}
		check_ajax_referer('speedpulse_ajax');
	}

	public function toggle(): void
	{
		$this->guard();
		$settings            = get_option('speedpulse_settings', []);
		$settings['enabled'] = empty($settings['enabled']);
		update_option('speedpulse_settings', $settings, false);
		\SpeedPulsePro\Core\Activator::syncEnabledFlag(! empty($settings['enabled']));
		wp_send_json_success([
			'enabled' => ! empty($settings['enabled']),
			'message' => ! empty($settings['enabled'])
				? 'مانیتورینگ روشن شد. صفحه را یک‌بار تازه‌سازی کنید.'
				: 'مانیتورینگ خاموش شد — سربار نزدیک به صفر.',
		]);
	}

	public function snapshot(): void
	{
		$this->guard();
		$snap = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap)) {
			$snap = $this->profiler->isEnabled() ? $this->profiler->snapshot() : [];
		}
		$dom = get_transient('speedpulse_dom_last');
		wp_send_json_success([
			'snapshot' => $snap,
			'dom'      => is_array($dom) ? $dom : null,
			'memory'   => get_transient('speedpulse_memory_report'),
		]);
	}

	public function domReport(): void
	{
		$this->guard();
		$blocking = json_decode(wp_unslash((string) ($_POST['blocking'] ?? '[]')), true);
		$data     = [
			'elements' => (int) ($_POST['elements'] ?? 0),
			'depth'    => (int) ($_POST['depth'] ?? 0),
			'warn'     => ! empty($_POST['warn']),
			'fonts'    => (int) ($_POST['fonts'] ?? 0),
			'blocking' => is_array($blocking) ? $blocking : [],
			'at'       => time(),
		];
		set_transient('speedpulse_dom_last', $data, 600);
		wp_send_json_success(['message' => 'گزارش DOM ذخیره شد.', 'data' => $data]);
	}

	public function debugLog(): void
	{
		$this->guard();
		wp_send_json_success((new LogStreamer())->classify());
	}

	public function clearLog(): void
	{
		$this->guard();
		$result = (new LogStreamer())->clear();
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function indexes(): void
	{
		$this->guard();
		wp_send_json_success(['items' => (new IndexOptimizer())->suggestions()]);
	}

	public function applyIndex(): void
	{
		$this->guard();
		$name   = sanitize_key((string) ($_POST['name'] ?? ''));
		$result = (new IndexOptimizer())->apply($name);
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function wooReport(): void
	{
		$this->guard();
		wp_send_json_success((new WooSurgeon($this->profiler))->cleanupReport());
	}

	public function wooCleanup(): void
	{
		$this->guard();
		$target = sanitize_key((string) ($_POST['target'] ?? ''));
		$result = (new WooSurgeon($this->profiler))->runCleanup($target);
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function stress(): void
	{
		$this->guard();
		$c = (int) ($_POST['concurrency'] ?? 50);
		$d = (int) ($_POST['duration'] ?? 5);
		wp_send_json_success((new StressTester())->run($c, $d));
	}

	public function crawler(): void
	{
		$this->guard();
		$cmd = sanitize_key((string) ($_POST['cmd'] ?? 'state'));
		$c   = new SiteCrawler();
		switch ($cmd) {
			case 'start':
				$result = $c->start();
				break;
			case 'pause':
				$result = $c->pause();
				break;
			case 'resume':
				$result = $c->resume();
				break;
			default:
				$result = ['ok' => true, 'message' => 'وضعیت خزش', 'state' => $c->state()];
				break;
		}
		wp_send_json_success($result);
	}

	public function aiAnalyze(): void
	{
		$this->guard();
		$snap = get_transient('speedpulse_last_snapshot');
		$log  = (new LogStreamer())->classify(120000);
		$woo  = (new WooSurgeon($this->profiler))->cleanupReport();
		$dom  = get_transient('speedpulse_dom_last');
		$result = (new AiClient())->analyze([
			'snapshot' => is_array($snap) ? $snap : [],
			'errors'   => $log,
			'woo'      => $woo,
			'dom'      => $dom,
		]);
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function patchGenerate(): void
	{
		$this->guard();
		$snap = get_transient('speedpulse_last_snapshot');
		$result = (new PatchGenerator())->generate([
			'snapshot' => is_array($snap) ? $snap : [],
			'ai_hint'  => (string) get_transient('speedpulse_ai_last_analysis'),
		]);
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function patchSave(): void
	{
		$this->guard();
		$code = wp_unslash((string) ($_POST['code'] ?? ''));
		$result = (new PatchGenerator())->savePending($code);
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function patchCanary(): void
	{
		$this->guard();
		$result = (new CanaryRunner())->runCanary();
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function patchRollback(): void
	{
		$this->guard();
		$result = (new PatchGenerator())->rollback();
		$result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result);
	}

	public function saveSettings(): void
	{
		$this->guard();
		$current = get_option('speedpulse_settings', []);
		$current['ai_provider']       = sanitize_text_field((string) ($_POST['ai_provider'] ?? 'openai'));
		$current['ai_model']          = sanitize_text_field((string) ($_POST['ai_model'] ?? 'gpt-4o'));
		$current['ai_api_key']        = sanitize_text_field((string) ($_POST['ai_api_key'] ?? ($current['ai_api_key'] ?? '')));
		$current['ai_base_url']       = esc_url_raw((string) ($_POST['ai_base_url'] ?? ''));
		$current['slow_query_ms']     = (float) ($_POST['slow_query_ms'] ?? 20);
		$current['dom_warn_elements'] = (int) ($_POST['dom_warn_elements'] ?? 800);
		$current['capture_queries']   = ! empty($_POST['capture_queries']);
		$current['capture_hooks']     = ! empty($_POST['capture_hooks']);
		$current['capture_network']   = ! empty($_POST['capture_network']);
		update_option('speedpulse_settings', $current, false);
		wp_send_json_success(['message' => 'تنظیمات ذخیره شد.', 'settings' => $this->publicSettings($current)]);
	}

	public function cronInventory(): void
	{
		$this->guard();
		wp_send_json_success((new CronAuditor($this->profiler))->inventory());
	}

	public function memoryReport(): void
	{
		$this->guard();
		wp_send_json_success(get_transient('speedpulse_memory_report') ?: ['message' => 'هنوز نمونه‌ای ثبت نشده است.']);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function publicSettings(array $settings): array
	{
		$out = $settings;
		if (! empty($out['ai_api_key'])) {
			$out['ai_api_key'] = str_repeat('*', 8) . substr((string) $out['ai_api_key'], -4);
		}
		return $out;
	}
}
