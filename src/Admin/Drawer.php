<?php
/**
 * پنل کشویی تاریک ادمین.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

use SpeedPulsePro\Core\Profiler;

final class Drawer
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		add_action('admin_footer', [$this, 'render']);
		add_action('wp_footer', [$this, 'render']);
	}

	public function render(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$path = SPEEDPULSE_PATH . 'templates/drawer.php';
		if (is_readable($path)) {
			$profiler = $this->profiler;
			include $path;
		}
	}
}
