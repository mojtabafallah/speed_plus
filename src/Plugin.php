<?php
/**
 * نقطه ورود اصلی افزونه اسپید‌پالس پرو.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro;

use SpeedPulsePro\Admin\AdminBar;
use SpeedPulsePro\Admin\AjaxHandlers;
use SpeedPulsePro\Admin\Drawer;
use SpeedPulsePro\Admin\ReportExporter;
use SpeedPulsePro\Admin\SettingsPage;
use SpeedPulsePro\AI\AiClient;
use SpeedPulsePro\AI\CanaryRunner;
use SpeedPulsePro\AI\PatchGenerator;
use SpeedPulsePro\Core\Profiler;
use SpeedPulsePro\Crawler\SiteCrawler;
use SpeedPulsePro\Cron\CronAuditor;
use SpeedPulsePro\Cron\MemoryLeakDetector;
use SpeedPulsePro\Database\QueryProfiler;
use SpeedPulsePro\Debug\LogStreamer;
use SpeedPulsePro\Frontend\DomInspector;
use SpeedPulsePro\Stress\StressTester;
use SpeedPulsePro\WooCommerce\WooSurgeon;

final class Plugin
{
	private static ?self $instance = null;

	private Profiler $profiler;

	public static function instance(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		$this->profiler = Profiler::instance();
	}

	public function boot(): void
	{
		// سربار نزدیک به صفر وقتی ضبط خاموش است.
		if ($this->profiler->isEnabled()) {
			$this->profiler->start();
			(new QueryProfiler($this->profiler))->boot();
			(new CronAuditor($this->profiler))->boot();
			(new MemoryLeakDetector($this->profiler))->boot();
			(new DomInspector())->boot();
			(new WooSurgeon($this->profiler))->boot();
		}

		if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
			(new AdminBar($this->profiler))->boot();
			(new Drawer($this->profiler))->boot();
			(new SettingsPage())->boot();
			(new AjaxHandlers($this->profiler))->boot();
			(new ReportExporter($this->profiler))->boot();
			(new LogStreamer())->boot();
			(new StressTester())->boot();
			(new SiteCrawler())->boot();
			(new AiClient())->boot();
			(new PatchGenerator())->boot();
			(new CanaryRunner())->boot();
		}
	}

	public function profiler(): Profiler
	{
		return $this->profiler;
	}
}
