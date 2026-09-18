<?php
/**
 * تشخیص منبع کد (هسته / قالب / افزونه) از روی مسیر فایل یا کالبک.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class SourceAttributor
{
	public static function fromFile(string $file): string
	{
		$file = wp_normalize_path($file);
		$pluginDir = wp_normalize_path(WP_PLUGIN_DIR);
		$muDir     = wp_normalize_path(WPMU_PLUGIN_DIR);
		$content   = wp_normalize_path(WP_CONTENT_DIR);
		$abspath   = wp_normalize_path(ABSPATH);

		if (str_starts_with($file, $pluginDir)) {
			$rel  = ltrim(substr($file, strlen($pluginDir)), '/');
			$parts = explode('/', $rel);
			return 'افزونه: ' . ($parts[0] ?? 'نامشخص');
		}

		if (str_starts_with($file, $muDir)) {
			$rel   = ltrim(substr($file, strlen($muDir)), '/');
			$parts = explode('/', $rel);
			return 'MU: ' . ($parts[0] ?? 'نامشخص');
		}

		$themeRoot = wp_normalize_path(get_theme_root());
		if (str_starts_with($file, $themeRoot)) {
			$rel   = ltrim(substr($file, strlen($themeRoot)), '/');
			$parts = explode('/', $rel);
			return 'قالب: ' . ($parts[0] ?? 'نامشخص');
		}

		if (str_starts_with($file, $abspath . 'wp-admin') || str_starts_with($file, $abspath . 'wp-includes')) {
			return 'هسته وردپرس';
		}

		if (str_starts_with($file, $content)) {
			return 'محتوا';
		}

		return 'نامشخص';
	}

	public static function fromCallable(mixed $callable): string
	{
		try {
			if (is_string($callable) && function_exists($callable)) {
				$ref = new \ReflectionFunction($callable);
				$file = $ref->getFileName();
				return $file ? self::fromFile($file) : 'هسته وردپرس';
			}
			if (is_array($callable) && isset($callable[0], $callable[1])) {
				$obj = $callable[0];
				$method = (string) $callable[1];
				if (is_object($obj)) {
					$ref = new \ReflectionMethod($obj, $method);
				} elseif (is_string($obj)) {
					$ref = new \ReflectionMethod($obj, $method);
				} else {
					return 'نامشخص';
				}
				$file = $ref->getFileName();
				return $file ? self::fromFile($file) : 'هسته وردپرس';
			}
			if ($callable instanceof \Closure) {
				$ref  = new \ReflectionFunction($callable);
				$file = $ref->getFileName();
				return $file ? self::fromFile($file) : 'بسته ناشناس';
			}
		} catch (\Throwable) {
			return 'نامشخص';
		}
		return 'نامشخص';
	}

	public static function describeCallable(mixed $callable): string
	{
		if (is_string($callable)) {
			return $callable . '()';
		}
		if (is_array($callable) && isset($callable[0], $callable[1])) {
			$class = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
			return $class . '::' . (string) $callable[1];
		}
		if ($callable instanceof \Closure) {
			return 'Closure';
		}
		return 'کالبک ناشناس';
	}

	/**
	 * استخراج منبع از استک‌تریس.
	 *
	 * @param list<array{file?:string,line?:int,function?:string,class?:string}> $trace
	 * @return array{source:string,file:string,line:int,function:string}
	 */
	public static function fromTrace(array $trace): array
	{
		foreach ($trace as $frame) {
			$file = (string) ($frame['file'] ?? '');
			if ($file === '' || str_contains($file, 'speedpulse-pro-ultra')) {
				continue;
			}
			if (str_contains($file, 'wp-db.php') || str_contains($file, 'wp-includes/plugin.php')) {
				continue;
			}
			return [
				'source'   => self::fromFile($file),
				'file'     => $file,
				'line'     => (int) ($frame['line'] ?? 0),
				'function' => (string) (($frame['class'] ?? '') !== ''
					? ($frame['class'] . ($frame['type'] ?? '::') . ($frame['function'] ?? ''))
					: ($frame['function'] ?? '')),
			];
		}

		return [
			'source'   => 'هسته وردپرس',
			'file'     => '',
			'line'     => 0,
			'function' => '',
		];
	}
}
