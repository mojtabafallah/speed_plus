<?php
/**
 * شناسایی رشد غیرعادی حافظه در طول درخواست.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Cron;

use SpeedPulsePro\Core\Profiler;

final class MemoryLeakDetector
{
	/** @var list<array{label:string,memory:int,delta:int}> */
	private array $samples = [];

	private int $lastMem = 0;

	public function __construct(private Profiler $profiler)
	{
	}

	public function boot(): void
	{
		$this->lastMem = memory_get_usage(true);
		add_action('plugins_loaded', fn () => $this->sample('پس از plugins_loaded'), 99);
		add_action('init', fn () => $this->sample('پس از init'), 99);
		add_action('wp_loaded', fn () => $this->sample('پس از wp_loaded'), 99);
		add_action('shutdown', fn () => $this->sample('shutdown'), 1);
		add_action('shutdown', [$this, 'analyze'], 2);
	}

	public function sample(string $label): void
	{
		$mem   = memory_get_usage(true);
		$delta = $mem - $this->lastMem;
		$this->samples[] = [
			'label'  => $label,
			'memory' => $mem,
			'delta'  => $delta,
		];
		$this->lastMem = $mem;
	}

	public function analyze(): void
	{
		$warnings = [];
		foreach ($this->samples as $s) {
			// رشد بیش از ۸ مگابایت در یک مرحله مشکوک است.
			if ($s['delta'] > 8 * 1048576) {
				$warnings[] = [
					'label'   => $s['label'],
					'delta_mb'=> round($s['delta'] / 1048576, 2),
					'message' => 'رشد ناگهانی حافظه — احتمال نشت یا بارگذاری آبجکت‌های بزرگ',
				];
			}
		}

		$limit = $this->memoryLimitBytes();
		$peak  = memory_get_peak_usage(true);
		$ratio = $limit > 0 ? ($peak / $limit) : 0;

		set_transient(
			'speedpulse_memory_report',
			[
				'samples'      => $this->samples,
				'warnings'     => $warnings,
				'peak_mb'      => round($peak / 1048576, 2),
				'limit_mb'     => $limit > 0 ? round($limit / 1048576, 2) : 0,
				'usage_ratio'  => round($ratio * 100, 1),
				'near_limit'   => $ratio >= 0.85,
			],
			300
		);

		if ($warnings !== []) {
			$this->profiler->mark('هشدار نشت حافظه', 'سیستم');
		}
	}

	private function memoryLimitBytes(): int
	{
		$limit = (string) ini_get('memory_limit');
		if ($limit === '' || $limit === '-1') {
			return 0;
		}
		$unit = strtolower(substr($limit, -1));
		$num  = (float) $limit;
		return match ($unit) {
			'g' => (int) ($num * 1073741824),
			'm' => (int) ($num * 1048576),
			'k' => (int) ($num * 1024),
			default => (int) $num,
		};
	}
}
