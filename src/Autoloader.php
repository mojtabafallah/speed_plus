<?php
/**
 * آتولودر PSR-4 برای فضای نام SpeedPulsePro.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro;

final class Autoloader
{
	private static string $baseDir = '';

	public static function register(string $baseDir): void
	{
		self::$baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
		spl_autoload_register([self::class, 'load']);
	}

	public static function load(string $class): void
	{
		$prefix = 'SpeedPulsePro\\';
		if (0 !== strpos($class, $prefix)) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$file     = self::$baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	}
}
