<?php
/**
 * اطلاعات کلی سرور، وردپرس، PHP، دیتابیس و منابع.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class SystemInfo
{
	/**
	 * @return array<string, mixed>
	 */
	public function collect(): array
	{
		global $wpdb, $wp_version;

		$memLimit = (string) ini_get('memory_limit');
		$memUsage = memory_get_usage(true);
		$memPeak  = memory_get_peak_usage(true);
		$memLimitBytes = $this->toBytes($memLimit);

		$load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
		$rusage = function_exists('getrusage') ? getrusage() : null;

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme = wp_get_theme();
		$plugins = get_plugins();
		$active = (array) get_option('active_plugins', []);

		$dbSize = $this->databaseSize();
		$diskFree = @disk_free_space(ABSPATH);
		$diskTotal = @disk_total_space(ABSPATH);

		$opcache = [];
		if (function_exists('opcache_get_status')) {
			$status = @opcache_get_status(false);
			if (is_array($status)) {
				$opcache = [
					'enabled'     => ! empty($status['opcache_enabled']),
					'memory_used' => isset($status['memory_usage']['used_memory']) ? (int) $status['memory_usage']['used_memory'] : 0,
					'memory_free' => isset($status['memory_usage']['free_memory']) ? (int) $status['memory_usage']['free_memory'] : 0,
					'hits'        => isset($status['opcache_statistics']['hits']) ? (int) $status['opcache_statistics']['hits'] : 0,
					'misses'      => isset($status['opcache_statistics']['misses']) ? (int) $status['opcache_statistics']['misses'] : 0,
				];
			}
		}

		return [
			'wordpress' => [
				'version'   => (string) $wp_version,
				'multisite' => is_multisite(),
				'debug'     => defined('WP_DEBUG') && WP_DEBUG,
				'debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
				'script_debug' => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG,
				'locale'    => get_locale(),
				'siteurl'   => site_url('/'),
				'home'      => home_url('/'),
			],
			'php' => [
				'version'          => PHP_VERSION,
				'sapi'             => PHP_SAPI,
				'os'               => PHP_OS,
				'memory_limit'     => $memLimit,
				'memory_limit_bytes' => $memLimitBytes,
				'max_execution'    => (string) ini_get('max_execution_time'),
				'max_input_vars'   => (string) ini_get('max_input_vars'),
				'upload_max'       => (string) ini_get('upload_max_filesize'),
				'post_max'         => (string) ini_get('post_max_size'),
				'timezone'         => date_default_timezone_get(),
				'extensions'       => [
					'curl'      => extension_loaded('curl'),
					'imagick'   => extension_loaded('imagick'),
					'gd'        => extension_loaded('gd'),
					'redis'     => extension_loaded('redis'),
					'memcached' => extension_loaded('memcached'),
					'ioncube'   => extension_loaded('ionCube Loader'),
				],
			],
			'memory' => [
				'usage_bytes'  => $memUsage,
				'peak_bytes'   => $memPeak,
				'limit_bytes'  => $memLimitBytes,
				'usage_human'  => size_format($memUsage),
				'peak_human'   => size_format($memPeak),
				'limit_human'  => $memLimit,
				'usage_percent'=> $memLimitBytes > 0 ? round(($memUsage / $memLimitBytes) * 100, 1) : 0,
				'peak_percent' => $memLimitBytes > 0 ? round(($memPeak / $memLimitBytes) * 100, 1) : 0,
			],
			'cpu' => [
				'cores'       => $this->cpuCores(),
				'load_1'      => is_array($load) ? round((float) $load[0], 2) : null,
				'load_5'      => is_array($load) ? round((float) $load[1], 2) : null,
				'load_15'     => is_array($load) ? round((float) $load[2], 2) : null,
				'user_time_ms'=> is_array($rusage) ? $this->rusageMs($rusage, 'ru_utime') : null,
				'sys_time_ms' => is_array($rusage) ? $this->rusageMs($rusage, 'ru_stime') : null,
			],
			'server' => [
				'software'     => isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field((string) $_SERVER['SERVER_SOFTWARE']) : '',
				'protocol'     => isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field((string) $_SERVER['SERVER_PROTOCOL']) : '',
				'https'        => is_ssl(),
				'document_root'=> defined('ABSPATH') ? ABSPATH : '',
				'disk_free'    => is_numeric($diskFree) ? size_format((int) $diskFree) : '—',
				'disk_total'   => is_numeric($diskTotal) ? size_format((int) $diskTotal) : '—',
				'disk_free_bytes' => is_numeric($diskFree) ? (int) $diskFree : 0,
				'disk_total_bytes'=> is_numeric($diskTotal) ? (int) $diskTotal : 0,
			],
			'database' => [
				'version' => is_object($wpdb) ? (string) $wpdb->db_version() : '',
				'prefix'  => is_object($wpdb) ? (string) $wpdb->prefix : '',
				'charset' => is_object($wpdb) ? (string) $wpdb->charset : '',
				'size'    => $dbSize['human'],
				'size_bytes' => $dbSize['bytes'],
				'tables'  => $dbSize['tables'],
			],
			'theme' => [
				'name'    => $theme->get('Name'),
				'version' => $theme->get('Version'),
				'parent'  => $theme->parent() ? $theme->parent()->get('Name') : '',
			],
			'plugins' => [
				'total'  => count($plugins),
				'active' => count($active),
				'active_list' => array_values(array_map(static function ($file) use ($plugins) {
					$p = $plugins[$file] ?? null;
					return [
						'file'    => (string) $file,
						'name'    => is_array($p) ? (string) ($p['Name'] ?? $file) : (string) $file,
						'version' => is_array($p) ? (string) ($p['Version'] ?? '') : '',
					];
				}, array_slice($active, 0, 40))),
			],
			'woocommerce' => [
				'active'  => class_exists('WooCommerce'),
				'version' => defined('WC_VERSION') ? WC_VERSION : '',
			],
			'cache' => [
				'object_cache' => wp_using_ext_object_cache(),
				'opcache'      => $opcache,
			],
			'generated_at' => wp_date('Y-m-d H:i:s'),
		];
	}

	/**
	 * نمونه زنده رم/سی‌پی‌یو برای polling.
	 *
	 * @return array<string, mixed>
	 */
	public function liveSample(): array
	{
		$usage = memory_get_usage(true);
		$peak  = memory_get_peak_usage(true);
		$real  = memory_get_usage(false);
		$limit = $this->toBytes((string) ini_get('memory_limit'));
		$load  = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
		$rusage = function_exists('getrusage') ? getrusage() : null;

		$snap = get_transient('speedpulse_last_snapshot');
		$attr = (is_array($snap) && isset($snap['attribution']) && is_array($snap['attribution']))
			? $snap['attribution']
			: [];

		$topSources = [];
		foreach ($attr as $name => $row) {
			$topSources[] = [
				'name'    => (string) $name,
				'cpu_ms'  => round((float) ($row['cpu'] ?? 0), 2),
				'queries' => (int) ($row['queries'] ?? 0),
				'hooks'   => (int) ($row['hooks'] ?? 0),
			];
			if (count($topSources) >= 8) {
				break;
			}
		}

		$breakdown = [
			[
				'label'  => 'حافظه واقعی PHP (emalloc)',
				'bytes'  => $real,
				'human'  => size_format($real),
				'note'   => 'حافظه تخصیص‌داده‌شده توسط موتور Zend',
			],
			[
				'label'  => 'حافظه سیستم (real)',
				'bytes'  => $usage,
				'human'  => size_format($usage),
				'note'   => 'شامل سربار سیستم برای این درخواست',
			],
			[
				'label'  => 'اوج حافظه این درخواست',
				'bytes'  => $peak,
				'human'  => size_format($peak),
				'note'   => 'بیشینه مصرف از شروع درخواست فعلی',
			],
		];

		return [
			'at' => time(),
			'at_human' => wp_date('H:i:s'),
			'memory' => [
				'usage_bytes'   => $usage,
				'peak_bytes'    => $peak,
				'real_bytes'    => $real,
				'limit_bytes'   => $limit,
				'usage_human'   => size_format($usage),
				'peak_human'    => size_format($peak),
				'real_human'    => size_format($real),
				'limit_human'   => (string) ini_get('memory_limit'),
				'usage_percent' => $limit > 0 ? round(($usage / $limit) * 100, 1) : 0,
				'peak_percent'  => $limit > 0 ? round(($peak / $limit) * 100, 1) : 0,
				'parts'         => $breakdown,
			],
			'cpu' => [
				'load_1'       => is_array($load) ? round((float) $load[0], 2) : null,
				'load_5'       => is_array($load) ? round((float) $load[1], 2) : null,
				'load_15'      => is_array($load) ? round((float) $load[2], 2) : null,
				'cores'        => $this->cpuCores(),
				'user_time_ms' => is_array($rusage) ? $this->rusageMs($rusage, 'ru_utime') : null,
				'sys_time_ms'  => is_array($rusage) ? $this->rusageMs($rusage, 'ru_stime') : null,
				'note'         => is_array($load)
					? 'Load Average سیستم (۱/۵/۱۵ دقیقه). در ویندوز ممکن است در دسترس نباشد.'
					: 'Load Average روی این سرور در دسترس نیست؛ زمان CPU درخواست فعلی از getrusage خوانده شده.',
			],
			'top_sources' => $topSources,
			'request' => [
				'uri'    => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '',
				'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field((string) $_SERVER['REQUEST_METHOD']) : '',
			],
		];
	}

	/**
	 * @return array{bytes:int,human:string,tables:int}
	 */
	private function databaseSize(): array
	{
		global $wpdb;
		$bytes = 0;
		$tables = 0;
		if (! is_object($wpdb)) {
			return ['bytes' => 0, 'human' => '—', 'tables' => 0];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$tables++;
				$bytes += (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
			}
		}
		return [
			'bytes'  => $bytes,
			'human'  => size_format($bytes),
			'tables' => $tables,
		];
	}

	private function cpuCores(): int
	{
		if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
			$n = getenv('NUMBER_OF_PROCESSORS');
			return $n ? (int) $n : 0;
		}
		if (is_readable('/proc/cpuinfo')) {
			$cpuinfo = @file_get_contents('/proc/cpuinfo');
			if (is_string($cpuinfo)) {
				return max(1, substr_count($cpuinfo, 'processor'));
			}
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $rusage
	 */
	private function rusageMs(array $rusage, string $key): float
	{
		$sec = (int) ($rusage[$key . '.tv_sec'] ?? 0);
		$usec = (int) ($rusage[$key . '.tv_usec'] ?? 0);
		return round(($sec * 1000) + ($usec / 1000), 2);
	}

	private function toBytes(string $val): int
	{
		$val = trim($val);
		if ($val === '' || $val === '-1') {
			return 0;
		}
		$unit = strtolower(substr($val, -1));
		$num  = (float) $val;
		switch ($unit) {
			case 'g':
				return (int) ($num * 1073741824);
			case 'm':
				return (int) ($num * 1048576);
			case 'k':
				return (int) ($num * 1024);
			default:
				return (int) $num;
		}
	}
}
