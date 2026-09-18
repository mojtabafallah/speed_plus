<?php
/**
 * اجرای آزمایشی Canary و بازگشت امن در صورت Fatal/افت عملکرد.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\AI;

final class CanaryRunner
{
	public function boot(): void
	{
		add_action('init', [$this, 'maybeLoadPatch'], 2);
	}

	public function maybeLoadPatch(): void
	{
		// پچ فقط اگر Canary تایید شده باشد از طریق PatchGenerator لود می‌شود.
		$meta = get_option('speedpulse_patch_meta', []);
		if (! empty($meta['active']) && empty($meta['canary_failed'])) {
			(new PatchGenerator())->loadActiveIfSafe();
		}
	}

	/**
	 * @return array{ok:bool,message:string,metrics:array<string,mixed>}
	 */
	public function runCanary(): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.', 'metrics' => []];
		}

		$code = (string) get_transient('speedpulse_pending_patch');
		if ($code === '') {
			return ['ok' => false, 'message' => 'پیش‌نویس پچ موجود نیست.', 'metrics' => []];
		}

		$generator = new PatchGenerator();
		if (! $generator->isSafePhp($code)) {
			return ['ok' => false, 'message' => 'کد از فیلتر ایمنی عبور نکرد.', 'metrics' => []];
		}

		$baseline = $this->probeHome();
		$tmp      = trailingslashit(WP_CONTENT_DIR) . 'speedpulse-canary-temp.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($tmp, $code);

		$canaryUrl = add_query_arg(
			[
				'speedpulse_canary' => '1',
				'_wpnonce'          => wp_create_nonce('speedpulse_canary'),
			],
			home_url('/')
		);

		// ساب‌ریکوئست ایزوله
		$t0  = microtime(true);
		$res = wp_remote_get(
			$canaryUrl,
			[
				'timeout' => 25,
				'headers' => [
					'X-SpeedPulse-Canary' => '1',
					'Cookie'              => '',
				],
			]
		);
		$ms = (microtime(true) - $t0) * 1000;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
		@unlink($tmp);

		if (is_wp_error($res)) {
			(new PatchGenerator())->rollback();
			update_option('speedpulse_patch_meta', ['active' => false, 'canary_failed' => true], false);
			return [
				'ok'      => false,
				'message' => 'Canary شکست خورد: ' . $res->get_error_message(),
				'metrics' => ['baseline' => $baseline, 'canary_ms' => round($ms, 2)],
			];
		}

		$codeHttp = (int) wp_remote_retrieve_response_code($res);
		$body     = (string) wp_remote_retrieve_body($res);
		$fatal    = str_contains($body, 'Fatal error') || str_contains($body, 'Parse error') || $codeHttp >= 500;
		$slower   = $baseline['ttfb_ms'] > 0 && $ms > ($baseline['ttfb_ms'] * 1.8);

		if ($fatal || $slower) {
			update_option('speedpulse_patch_meta', ['active' => false, 'canary_failed' => true], false);
			return [
				'ok'      => false,
				'message' => $fatal
					? 'Fatal Error در Canary — پچ اعمال نشد.'
					: 'افت عملکرد بیش از حد در Canary — پچ رد شد.',
				'metrics' => [
					'baseline'  => $baseline,
					'canary_ms' => round($ms, 2),
					'http'      => $codeHttp,
				],
			];
		}

		$apply = $generator->applyApproved();
		return [
			'ok'      => $apply['ok'],
			'message' => $apply['ok'] ? 'Canary موفق بود و پچ اعمال شد.' : $apply['message'],
			'metrics' => [
				'baseline'  => $baseline,
				'canary_ms' => round($ms, 2),
				'http'      => $codeHttp,
			],
		];
	}

	/**
	 * @return array{ttfb_ms:float,code:int}
	 */
	private function probeHome(): array
	{
		$t0  = microtime(true);
		$res = wp_remote_get(home_url('/'), ['timeout' => 20]);
		$ms  = (microtime(true) - $t0) * 1000;
		return [
			'ttfb_ms' => round($ms, 2),
			'code'    => is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res),
		];
	}

}
