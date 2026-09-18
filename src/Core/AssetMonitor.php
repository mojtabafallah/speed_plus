<?php
/**
 * مانیتور استایل‌ها و اسکریپت‌های صف‌شده غیرضروری.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class AssetMonitor
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		add_action('wp_print_scripts', [$this, 'captureScripts'], PHP_INT_MAX);
		add_action('wp_print_styles', [$this, 'captureStyles'], PHP_INT_MAX);
		add_action('admin_print_scripts', [$this, 'captureScripts'], PHP_INT_MAX);
		add_action('admin_print_styles', [$this, 'captureStyles'], PHP_INT_MAX);
	}

	public function captureScripts(): void
	{
		global $wp_scripts;
		if (! $wp_scripts instanceof \WP_Scripts) {
			return;
		}
		foreach ((array) $wp_scripts->done as $handle) {
			$obj = $wp_scripts->registered[$handle] ?? null;
			if (! $obj) {
				continue;
			}
			$this->profiler->addAsset([
				'type'     => 'script',
				'handle'   => (string) $handle,
				'src'      => (string) ($obj->src ?? ''),
				'deps'     => array_values((array) ($obj->deps ?? [])),
				'in_footer'=> ! empty($obj->extra['group']),
				'source'   => $this->guessSource((string) ($obj->src ?? '')),
			]);
		}
	}

	public function captureStyles(): void
	{
		global $wp_styles;
		if (! $wp_styles instanceof \WP_Styles) {
			return;
		}
		foreach ((array) $wp_styles->done as $handle) {
			$obj = $wp_styles->registered[$handle] ?? null;
			if (! $obj) {
				continue;
			}
			$this->profiler->addAsset([
				'type'   => 'style',
				'handle' => (string) $handle,
				'src'    => (string) ($obj->src ?? ''),
				'deps'   => array_values((array) ($obj->deps ?? [])),
				'source' => $this->guessSource((string) ($obj->src ?? '')),
			]);
		}
	}

	private function guessSource(string $src): string
	{
		if ($src === '' || str_starts_with($src, 'data:')) {
			return 'داخلی';
		}
		$path = wp_normalize_path((string) wp_parse_url($src, PHP_URL_PATH));
		if ($path === '') {
			return 'خارجی';
		}
		$full = wp_normalize_path(ABSPATH . ltrim($path, '/'));
		if (is_readable($full)) {
			return SourceAttributor::fromFile($full);
		}
		if (str_contains($path, '/plugins/')) {
			$parts = explode('/plugins/', $path);
			$tail  = $parts[1] ?? '';
			$slug  = explode('/', $tail)[0] ?? '';
			return $slug !== '' ? 'افزونه: ' . $slug : 'افزونه';
		}
		if (str_contains($path, '/themes/')) {
			$parts = explode('/themes/', $path);
			$tail  = $parts[1] ?? '';
			$slug  = explode('/', $tail)[0] ?? '';
			return $slug !== '' ? 'قالب: ' . $slug : 'قالب';
		}
		return 'خارجی';
	}
}
