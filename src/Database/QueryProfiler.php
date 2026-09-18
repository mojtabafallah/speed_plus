<?php
/**
 * پروفایلر فوق‌عمیق کوئری‌های دیتابیس با استک‌تریس و تشخیص N+1.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Database;

use SpeedPulsePro\Core\Profiler;
use SpeedPulsePro\Core\SourceAttributor;

final class QueryProfiler
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		// اگر ثابت از قبل false باشد، از طریق پرچم MU در درخواست بعدی فعال می‌شود.
		add_filter('log_query_custom_data', [$this, 'enrichQuery'], 10, 5);
		add_action('shutdown', [$this, 'harvest'], 0);
	}

	/**
	 * @param array<string, mixed> $queryData
	 * @return array<string, mixed>
	 */
	public function enrichQuery(array $queryData, string $query, float $queryTime, string $queryCallstack, float $queryStart): array
	{
		$queryData['speedpulse'] = [
			'sql'       => $query,
			'time_ms'   => $queryTime * 1000,
			'callstack' => $queryCallstack,
			'start'     => $queryStart,
		];
		return $queryData;
	}

	public function harvest(): void
	{
		global $wpdb;
		if (! isset($wpdb->queries) || ! is_array($wpdb->queries)) {
			return;
		}

		$settings   = get_option('speedpulse_settings', []);
		$slowMs     = (float) ($settings['slow_query_ms'] ?? 20.0);
		$explainer  = new ExplainAnalyzer();
		$seenHashes = [];

		foreach ($wpdb->queries as $row) {
			$sql       = (string) ($row[0] ?? '');
			$timeSec   = (float) ($row[1] ?? 0);
			$stack     = (string) ($row[2] ?? '');
			$timeMs    = $timeSec * 1000;
			$trace     = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
			$attr      = SourceAttributor::fromTrace($this->parseStackToFrames($stack));
			$normalized = $this->normalizeSql($sql);
			$hash       = md5($normalized);

			$nPlusOne = false;
			if (isset($seenHashes[$hash])) {
				$seenHashes[$hash]++;
				if ($seenHashes[$hash] >= 3 && preg_match('/\bWHERE\b.+\b(=|IN)\b/i', $sql)) {
					$nPlusOne = true;
				}
			} else {
				$seenHashes[$hash] = 1;
			}

			$explain = null;
			if ($timeMs >= $slowMs && $this->isSelect($sql)) {
				$explain = $explainer->analyze($sql);
			}

			$this->profiler->addQuery([
				'sql'            => $this->truncate($sql, 2000),
				'sql_normalized' => $normalized,
				'time_ms'        => round($timeMs, 3),
				'source'         => $attr['source'],
				'file'           => $attr['file'],
				'line'           => $attr['line'],
				'function'       => $attr['function'],
				'stack'          => $this->truncate($stack, 1500),
				'n_plus_one'     => $nPlusOne,
				'explain'        => $explain,
				'slow'           => $timeMs >= $slowMs,
			]);
		}
	}

	/**
	 * @return list<array{file?:string,line?:int,function?:string}>
	 */
	private function parseStackToFrames(string $stack): array
	{
		$frames = [];
		$lines  = preg_split('/\s*,\s*/', $stack) ?: [];
		foreach ($lines as $line) {
			$frames[] = ['function' => trim($line)];
		}
		// تکمیل با backtrace فعلی برای مسیر فایل.
		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
		return array_merge($bt, $frames);
	}

	private function normalizeSql(string $sql): string
	{
		$sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
		$sql = preg_replace("/'[^']*'/", '?', $sql) ?? $sql;
		$sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;
		return $sql;
	}

	private function isSelect(string $sql): bool
	{
		return (bool) preg_match('/^\s*(SELECT|SHOW|EXPLAIN)\b/i', $sql);
	}

	private function truncate(string $text, int $max): string
	{
		return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
	}
}
