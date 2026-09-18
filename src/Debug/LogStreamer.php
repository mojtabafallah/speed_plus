<?php
/**
 * خواندن امن و تکه‌ای debug.log + دسته‌بندی خطاها.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Debug;

final class LogStreamer
{
	public function boot(): void
	{
		// هندلرها از طریق AjaxHandlers فراخوانی می‌شوند.
	}

	public function logPath(): string
	{
		if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '1' && WP_DEBUG_LOG !== '') {
			return WP_DEBUG_LOG;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * خواندن از انتهای فایل بدون بارگذاری کل محتوا در رم.
	 *
	 * @return array{ok:bool,lines:list<string>,size:int,message:string}
	 */
	public function readTail(int $bytes = 65536): array
	{
		$path = $this->logPath();
		if (! is_readable($path)) {
			return ['ok' => false, 'lines' => [], 'size' => 0, 'message' => 'فایل debug.log یافت نشد یا قابل خواندن نیست.'];
		}

		$size = (int) filesize($path);
		$fp   = fopen($path, 'rb');
		if (false === $fp) {
			return ['ok' => false, 'lines' => [], 'size' => $size, 'message' => 'باز کردن فایل ممکن نشد.'];
		}

		$read = min($bytes, $size);
		if ($read > 0) {
			fseek($fp, -$read, SEEK_END);
		}
		$data = stream_get_contents($fp);
		fclose($fp);

		$lines = preg_split("/\r\n|\n|\r/", (string) $data) ?: [];
		// حذف خط ناقص اول
		if ($size > $bytes && $lines !== []) {
			array_shift($lines);
		}

		return [
			'ok'      => true,
			'lines'   => array_values(array_filter($lines, static fn (string $l): bool => $l !== '')),
			'size'    => $size,
			'message' => 'خواندن موفق',
		];
	}

	/**
	 * @return array{ok:bool,categories:array<string,array{count:int,samples:list<array<string,mixed>>}>,total:int,message:string}
	 */
	public function classify(int $bytes = 262144): array
	{
		$tail = $this->readTail($bytes);
		if (! $tail['ok']) {
			return [
				'ok'         => false,
				'categories' => [],
				'total'      => 0,
				'message'    => $tail['message'],
			];
		}

		$classifier = new ErrorClassifier();
		$cats       = [
			'Fatal'      => ['count' => 0, 'samples' => []],
			'Deprecated' => ['count' => 0, 'samples' => []],
			'Warning'    => ['count' => 0, 'samples' => []],
			'Notice'     => ['count' => 0, 'samples' => []],
			'دیگر'       => ['count' => 0, 'samples' => []],
		];

		$fingerprint = [];
		foreach ($tail['lines'] as $line) {
			$parsed = $classifier->parseLine($line);
			$type   = $parsed['type'];
			if (! isset($cats[$type])) {
				$type = 'دیگر';
			}
			$cats[$type]['count']++;
			$key = md5($parsed['message']);
			if (! isset($fingerprint[$key])) {
				$fingerprint[$key] = 0;
			}
			$fingerprint[$key]++;
			if (count($cats[$type]['samples']) < 8) {
				$parsed['repeats'] = $fingerprint[$key];
				$parsed['fix']    = $classifier->offlineFixHint($parsed);
				$cats[$type]['samples'][] = $parsed;
			}
		}

		$total = array_sum(array_column($cats, 'count'));

		return [
			'ok'         => true,
			'categories' => $cats,
			'total'      => $total,
			'message'    => 'دسته‌بندی انجام شد',
			'size'       => $tail['size'],
		];
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function clear(): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}
		$path = $this->logPath();
		if (! file_exists($path)) {
			return ['ok' => true, 'message' => 'فایلی برای پاک‌سازی وجود ندارد.'];
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = false !== file_put_contents($path, '');
		return [
			'ok'      => $ok,
			'message' => $ok ? 'لاگ با موفقیت پاک شد.' : 'پاک‌سازی ناموفق بود.',
		];
	}
}
