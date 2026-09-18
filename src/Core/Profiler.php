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
		return self::$instance ??= new self();
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

		return [
			'enabled'         => $this->enabled,
			'ttfb_ms'         => round($this->ttfbMs > 0 ? $this->ttfbMs : (microtime(true) - $this->startTime) * 1000, 2),
			'cpu_ms'          => round($cpuMs, 2),
			'memory_mb'       => round(($peakMem - $this->startMemory) / 1048576, 2),
			'peak_memory_mb'  => round($peakMem / 1048576, 2),
			'query_count'     => count($this->queries),
			'slowest_source'  => $this->slowestSource,
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
