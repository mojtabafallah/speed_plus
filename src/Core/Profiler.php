<?php
/**
 * موتور پروفایلر با سربار نزدیک به صفر در حالت خاموش.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class Profiler
{
	private static ?self $instance = null;

	private bool $enabled = false;
	private float $startTime = 0.0;
	private int $startMemory = 0;
	/** @var array<string, mixed>|null */
	private ?array $startRusage = null;

	/** @var list<array{label:string,time:float,memory:int,source:string}> */
	private array $timeline = [];

	/** @var list<array<string, mixed>> */
	private array $hooks = [];

	/** @var list<array<string, mixed>> */
	private array $queries = [];

	/** @var list<array<string, mixed>> */
	private array $network = [];

	/** @var array<string, array{cpu:float,memory:int,hooks:int,queries:int}> */
	private array $attribution = [];

	/** @var list<array<string, mixed>> */
	private array $assets = [];

	/** @var list<array<string, mixed>> */
	private array $cronEvents = [];

	/** @var list<array<string, mixed>> */
	private array $wooEvents = [];

	private float $ttfbMs = 0.0;
	private string $slowestSource = 'هسته وردپرس';

	public static function instance(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		$settings      = get_option('speedpulse_settings', []);
		$this->enabled = ! empty($settings['enabled']);
	}

	public function isEnabled(): bool
	{
		return $this->enabled;
	}

	/**
	 * بررسی سریع بدون بارگذاری کامل — برای مسیرهای حساس.
	 */
	public static function quickEnabled(): bool
	{
		static $cached = null;
		if (null !== $cached) {
			return $cached;
		}
		$settings = get_option('speedpulse_settings', []);
		$cached   = ! empty($settings['enabled']);
		return $cached;
	}

	public function start(): void
	{
		if (! $this->enabled) {
			return;
		}

		$early = $GLOBALS['speedpulse_early'] ?? null;
		$this->startTime   = is_array($early) && isset($early['start'])
			? (float) $early['start']
			: (defined('SPEEDPULSE_REQUEST_START') ? (float) SPEEDPULSE_REQUEST_START : microtime(true));
		$this->startMemory = is_array($early) && isset($early['mem_start'])
			? (int) $early['mem_start']
			: memory_get_usage(true);
		$this->startRusage = (is_array($early) && isset($early['rusage']) && is_array($early['rusage']))
			? $early['rusage']
			: (function_exists('getrusage') ? getrusage() : null);

		$this->mark('شروع درخواست', 'هسته');

		add_action('muplugins_loaded', fn () => $this->mark('بارگذاری MU-Plugins', 'هسته'), 0);
		add_action('plugins_loaded', fn () => $this->mark('بارگذاری افزونه‌ها', 'هسته'), 0);
		add_action('setup_theme', fn () => $this->mark('آماده‌سازی قالب', 'قالب'), 0);
		add_action('after_setup_theme', fn () => $this->mark('پس از راه‌اندازی قالب', 'قالب'), 0);
		add_action('init', fn () => $this->mark('init', 'هسته'), 0);
		add_action('wp_loaded', fn () => $this->mark('wp_loaded', 'هسته'), 0);
		add_action('template_redirect', fn () => $this->mark('template_redirect', 'قالب'), 0);
		add_action('wp', fn () => $this->mark('wp', 'هسته'), 0);

		$settings = get_option('speedpulse_settings', []);
		if (! empty($settings['capture_hooks'])) {
			(new HookProfiler($this))->boot();
		}
		if (! empty($settings['capture_network'])) {
			(new NetworkTracker($this))->boot();
		}
		(new AssetMonitor($this))->boot();

		add_action('shutdown', [$this, 'finalize'], PHP_INT_MAX);
	}

	public function mark(string $label, string $source = 'هسته'): void
	{
		if (! $this->enabled) {
			return;
		}

		$this->timeline[] = [
			'label'  => $label,
			'time'   => (microtime(true) - $this->startTime) * 1000,
			'memory' => memory_get_usage(true) - $this->startMemory,
			'source' => $source,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addHook(array $row): void
	{
		$this->hooks[] = $row;
		$src = (string) ($row['source'] ?? 'هسته');
		$this->bumpAttribution($src, (float) ($row['cpu_ms'] ?? 0), (int) ($row['memory'] ?? 0), 1, 0);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addQuery(array $row): void
	{
		$this->queries[] = $row;
		$src = (string) ($row['source'] ?? 'هسته');
		$this->bumpAttribution($src, (float) ($row['time_ms'] ?? 0), 0, 0, 1);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addNetwork(array $row): void
	{
		$this->network[] = $row;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addAsset(array $row): void
	{
		$this->assets[] = $row;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addCron(array $row): void
	{
		$this->cronEvents[] = $row;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public function addWoo(array $row): void
	{
		$this->wooEvents[] = $row;
	}

	private function bumpAttribution(string $source, float $cpu, int $memory, int $hooks, int $queries): void
	{
		if (! isset($this->attribution[$source])) {
			$this->attribution[$source] = [
				'cpu'     => 0.0,
				'memory'  => 0,
				'hooks'   => 0,
				'queries' => 0,
			];
		}
		$this->attribution[$source]['cpu']     += $cpu;
		$this->attribution[$source]['memory']  += $memory;
		$this->attribution[$source]['hooks']   += $hooks;
		$this->attribution[$source]['queries'] += $queries;
	}

	public function finalize(): void
	{
		if (! $this->enabled) {
			return;
		}

		$this->mark('shutdown', 'هسته');
		$this->ttfbMs = (microtime(true) - $this->startTime) * 1000;

		if ($this->attribution !== []) {
			arsort($this->attribution);
			$keys = array_keys($this->attribution);
			$this->slowestSource = (string) ($keys[0] ?? 'هسته وردپرس');
			// مرتب‌سازی بر اساس cpu
			uasort(
				$this->attribution,
				static fn (array $a, array $b): int => $b['cpu'] <=> $a['cpu']
			);
			$keys = array_keys($this->attribution);
			$this->slowestSource = (string) ($keys[0] ?? 'هسته وردپرس');
		}

		set_transient('speedpulse_last_snapshot', $this->snapshot(), 300);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(): array
	{
		$peakMem = memory_get_peak_usage(true);
		$cpuMs   = $this->cpuDeltaMs();
		$totalMs = $this->ttfbMs > 0
			? $this->ttfbMs
			: (microtime(true) - $this->startTime) * 1000;
		$breakdown = $this->buildTimeBreakdown($totalMs);

		return [
			'enabled'         => $this->enabled,
			'ttfb_ms'         => round($totalMs, 2),
			'total_ms'        => round($totalMs, 2),
			'total_sec'       => round($totalMs / 1000, 3),
			'total_human'     => self::formatDuration($totalMs),
			'cpu_ms'          => round($cpuMs, 2),
			'memory_mb'       => round(($peakMem - $this->startMemory) / 1048576, 2),
			'peak_memory_mb'  => round($peakMem / 1048576, 2),
			'query_count'     => count($this->queries),
			'slowest_source'  => $this->slowestSource,
			'time_breakdown'  => $breakdown,
			'timeline'        => $this->timeline,
			'hooks'           => $this->topHooks(40),
			'queries'         => $this->queries,
			'network'         => $this->network,
			'assets'          => $this->assets,
			'attribution'     => $this->attribution,
			'cron'            => $this->cronEvents,
			'woo'             => $this->wooEvents,
			'duplicates'      => $this->findDuplicateQueries(),
			'generated_at'    => gmdate('c'),
			'url'             => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '',
		];
	}

	/**
	 * خلاصه زمان کل + جزئیات سهم هر بخش.
	 *
	 * @return array{
	 *   total_ms:float,
	 *   total_human:string,
	 *   items:list<array{key:string,label:string,ms:float,sec:float,human:string,percent:float,count:int,note:string}>
	 * }
	 */
	private function buildTimeBreakdown(float $totalMs): array
	{
		$queryMs = 0.0;
		foreach ($this->queries as $q) {
			$queryMs += (float) ($q['time_ms'] ?? 0);
		}

		$networkMs = 0.0;
		$networkCount = 0;
		foreach ($this->network as $n) {
			if (! empty($n['blocking'])) {
				$networkMs += (float) ($n['time_ms'] ?? 0);
				$networkCount++;
			}
		}

		$hooksMs = 0.0;
		foreach ($this->hooks as $h) {
			$hooksMs += (float) ($h['cpu_ms'] ?? 0);
		}

		$cronMs = 0.0;
		$cronCount = 0;
		foreach ($this->cronEvents as $c) {
			if (($c['type'] ?? '') === 'action_scheduler' || ($c['type'] ?? '') === 'schedule') {
				$cronCount++;
			}
			if (isset($c['duration_ms'])) {
				$cronMs += (float) $c['duration_ms'];
			}
		}

		// سایر = باقی‌مانده دیوار زمانی پس از کوئری و شبکه مسدودکننده
		$accounted = $queryMs + $networkMs;
		$otherMs   = max(0.0, $totalMs - $accounted);

		$raw = [
			[
				'key'   => 'queries',
				'label' => 'کوئری‌های دیتابیس',
				'ms'    => $queryMs,
				'count' => count($this->queries),
				'note'  => 'مجموع زمان اجرای SQL',
			],
			[
				'key'   => 'network',
				'label' => 'درخواست‌های شبکه (مسدودکننده)',
				'ms'    => $networkMs,
				'count' => $networkCount,
				'note'  => 'wp_remote_* / HTTP مسدودکننده',
			],
			[
				'key'   => 'hooks',
				'label' => 'هوک‌های کلیدی اندازه‌گیری‌شده',
				'ms'    => $hooksMs,
				'count' => count($this->hooks),
				'note'  => 'ممکن است با کوئری هم‌پوشانی داشته باشد؛ جداگانه نمایش داده می‌شود',
			],
			[
				'key'   => 'other',
				'label' => 'سایر پردازش PHP / قالب / افزونه‌ها',
				'ms'    => $otherMs,
				'count' => 0,
				'note'  => 'باقی‌مانده زمان کل پس از کوئری و شبکه',
			],
		];

		if ($cronCount > 0) {
			$raw[] = [
				'key'   => 'cron',
				'label' => 'رویدادهای زمان‌بندی‌شده',
				'ms'    => $cronMs,
				'count' => $cronCount,
				'note'  => 'WP-Cron / Action Scheduler در همین درخواست',
			];
		}

		$items = [];
		foreach ($raw as $row) {
			$ms = (float) $row['ms'];
			$pct = $totalMs > 0 ? ($ms / $totalMs) * 100 : 0.0;
			$items[] = [
				'key'     => $row['key'],
				'label'   => $row['label'],
				'ms'      => round($ms, 2),
				'sec'     => round($ms / 1000, 3),
				'human'   => self::formatDuration($ms),
				'percent' => round($pct, 1),
				'count'   => (int) $row['count'],
				'note'    => $row['note'],
			];
		}

		return [
			'total_ms'    => round($totalMs, 2),
			'total_sec'   => round($totalMs / 1000, 3),
			'total_human' => self::formatDuration($totalMs),
			'items'       => $items,
			'summary_fa'  => $this->buildSummaryFa($totalMs, $items),
		];
	}

	/**
	 * @param list<array{label:string,human:string,percent:float,count:int,key:string}> $items
	 */
	private function buildSummaryFa(float $totalMs, array $items): string
	{
		$parts = [];
		foreach ($items as $item) {
			if (($item['key'] ?? '') === 'hooks') {
				continue; // در جمله خلاصه فقط اجزای غیرهم‌پوشان
			}
			if ((float) ($item['ms'] ?? 0) <= 0 && (int) ($item['count'] ?? 0) === 0) {
				continue;
			}
			$extra = ((int) ($item['count'] ?? 0) > 0) ? ' (' . (int) $item['count'] . ' مورد)' : '';
			$parts[] = $item['label'] . ': ' . $item['human'] . ' — ' . $item['percent'] . '٪' . $extra;
		}

		return 'کل زمان لود: ' . self::formatDuration($totalMs) . '. جزئیات: ' . implode(' | ', $parts);
	}

	/**
	 * نمایش خوانای مدت زمان به فارسی.
	 */
	public static function formatDuration(float $ms): string
	{
		if ($ms >= 60000) {
			$min = (int) floor($ms / 60000);
			$rem = ($ms % 60000) / 1000;
			return $min . ' دقیقه و ' . number_format($rem, 2) . ' ثانیه';
		}
		if ($ms >= 1000) {
			return number_format($ms / 1000, 3) . ' ثانیه';
		}
		return number_format($ms, 2) . ' میلی‌ثانیه';
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function topHooks(int $limit): array
	{
		$hooks = $this->hooks;
		usort(
			$hooks,
			static fn (array $a, array $b): int => ((float) ($b['cpu_ms'] ?? 0)) <=> ((float) ($a['cpu_ms'] ?? 0))
		);
		return array_slice($hooks, 0, $limit);
	}

	/**
	 * @return list<array{sql:string,count:int,total_ms:float}>
	 */
	private function findDuplicateQueries(): array
	{
		$map = [];
		foreach ($this->queries as $q) {
			$key = md5((string) ($q['sql_normalized'] ?? $q['sql'] ?? ''));
			if (! isset($map[$key])) {
				$map[$key] = [
					'sql'      => (string) ($q['sql'] ?? ''),
					'count'    => 0,
					'total_ms' => 0.0,
				];
			}
			$map[$key]['count']++;
			$map[$key]['total_ms'] += (float) ($q['time_ms'] ?? 0);
		}

		$dups = array_values(array_filter($map, static fn (array $r): bool => $r['count'] > 1));
		usort($dups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
		return array_slice($dups, 0, 30);
	}

	private function cpuDeltaMs(): float
	{
		if (! function_exists('getrusage') || null === $this->startRusage) {
			return 0.0;
		}
		$now = getrusage();
		if (! is_array($now)) {
			return 0.0;
		}
		$uStart = ((int) ($this->startRusage['ru_utime.tv_sec'] ?? 0)) * 1e6 + (int) ($this->startRusage['ru_utime.tv_usec'] ?? 0);
		$sStart = ((int) ($this->startRusage['ru_stime.tv_sec'] ?? 0)) * 1e6 + (int) ($this->startRusage['ru_stime.tv_usec'] ?? 0);
		$uNow   = ((int) ($now['ru_utime.tv_sec'] ?? 0)) * 1e6 + (int) ($now['ru_utime.tv_usec'] ?? 0);
		$sNow   = ((int) ($now['ru_stime.tv_sec'] ?? 0)) * 1e6 + (int) ($now['ru_stime.tv_usec'] ?? 0);
		return (($uNow + $sNow) - ($uStart + $sStart)) / 1000;
	}

	public function elapsedMs(): float
	{
		return (microtime(true) - $this->startTime) * 1000;
	}
}
