<?php
/**
 * شبیه‌ساز تست فشار داخلی.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Stress;

final class StressTester
{
	public function boot(): void
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run(int $concurrency = 50, int $durationSec = 5): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}

		$concurrency = max(5, min(200, $concurrency));
		$durationSec = max(1, min(15, $durationSec));
		$url         = home_url('/');
		$deadline    = microtime(true) + $durationSec;

		$times  = [];
		$errors = 0;
		$sent   = 0;
		$batch  = max(5, (int) ceil($concurrency / 5));

		while (microtime(true) < $deadline && $sent < $concurrency * 2) {
			$requests = [];
			for ($i = 0; $i < $batch; $i++) {
				$key = 'r' . $sent . '_' . $i;
				$requests[$key] = [
					'url'  => add_query_arg('speedpulse_stress', (string) wp_rand(1, 999999), $url),
					'type' => 'GET',
					'headers' => [
						'X-SpeedPulse-Stress' => '1',
						'Cache-Control'       => 'no-cache',
					],
				];
			}

			foreach ($requests as $req) {
				$t0  = microtime(true);
				$res = wp_remote_get(
					$req['url'],
					[
						'timeout'   => 8,
						'blocking'  => true,
						'headers'   => $req['headers'],
					]
				);
				$times[] = (microtime(true) - $t0) * 1000;
				$sent++;
				if (is_wp_error($res)) {
					$errors++;
					continue;
				}
				$code = (int) wp_remote_retrieve_response_code($res);
				if ($code >= 500 || $code === 0) {
					$errors++;
				}
			}
		}

		sort($times);
		$count = count($times);
		$avg   = $count ? array_sum($times) / $count : 0;
		$p95   = $count ? $times[(int) floor(($count - 1) * 0.95)] : 0;
		$min   = $count ? $times[0] : 0;
		$max   = $count ? $times[$count - 1] : 0;
		$drop  = $min > 0 ? (($max - $min) / $min) * 100 : 0;

		return [
			'ok'            => true,
			'message'       => 'تست فشار به پایان رسید.',
			'concurrency'   => $concurrency,
			'duration_sec'  => $durationSec,
			'requests'      => $sent,
			'errors'        => $errors,
			'avg_ttfb_ms'   => round($avg, 2),
			'p95_ttfb_ms'   => round($p95, 2),
			'min_ms'        => round($min, 2),
			'max_ms'        => round($max, 2),
			'ttfb_drop_pct' => round($drop, 1),
			'stable'        => $errors === 0 && $drop < 150,
		];
	}
}
