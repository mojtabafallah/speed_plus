<?php
/**
 * سازگاری با PHP 7.4 — پلی‌فیل توابع رایج PHP 8.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! function_exists('str_contains')) {
	/**
	 * @param string $haystack
	 * @param string $needle
	 */
	function str_contains($haystack, $needle) {
		return $needle === '' || false !== strpos((string) $haystack, (string) $needle);
	}
}

if (! function_exists('str_starts_with')) {
	/**
	 * @param string $haystack
	 * @param string $needle
	 */
	function str_starts_with($haystack, $needle) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		return $needle === '' || 0 === strncmp($haystack, $needle, strlen($needle));
	}
}

if (! function_exists('str_ends_with')) {
	/**
	 * @param string $haystack
	 * @param string $needle
	 */
	function str_ends_with($haystack, $needle) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;
		if ($needle === '') {
			return true;
		}
		$len = strlen($needle);
		return substr($haystack, -$len) === $needle;
	}
}
