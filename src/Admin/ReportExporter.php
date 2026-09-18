<?php
/**
 * خروجی گزارش سلامت HTML / JSON.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

use SpeedPulsePro\Core\Profiler;
use SpeedPulsePro\Debug\LogStreamer;
use SpeedPulsePro\WooCommerce\WooSurgeon;

final class ReportExporter
{
	public function __construct(private Profiler $profiler)
	{
	}

	public function boot(): void
	{
		add_action('admin_post_speedpulse_export', [$this, 'export']);
	}

	public function export(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die('دسترسی کافی ندارید.');
		}
		check_admin_referer('speedpulse_export');

		$format = sanitize_key((string) ($_GET['format'] ?? 'json'));
		$snap   = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap)) {
			$snap = [];
		}

		$payload = [
			'title'     => 'کارنامه سلامت اسپید‌پالس پرو',
			'generated' => wp_date('Y-m-d H:i:s'),
			'site'      => home_url('/'),
			'snapshot'  => $snap,
			'errors'    => (new LogStreamer())->classify(100000),
			'woo'       => (new WooSurgeon($this->profiler))->cleanupReport(),
			'dom'       => get_transient('speedpulse_dom_last'),
			'memory'    => get_transient('speedpulse_memory_report'),
			'ai'        => get_transient('speedpulse_ai_last_analysis'),
		];

		if ($format === 'html') {
			nocache_headers();
			header('Content-Type: text/html; charset=utf-8');
			header('Content-Disposition: attachment; filename="speedpulse-report.html"');
			echo $this->toHtml($payload);
			exit;
		}

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="speedpulse-report.json"');
		echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		exit;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function toHtml(array $payload): string
	{
		$snap = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
		$ttfb = esc_html((string) ($snap['ttfb_ms'] ?? '—'));
		$q    = esc_html((string) ($snap['query_count'] ?? '—'));
		$mem  = esc_html((string) ($snap['memory_mb'] ?? '—'));
		$slow = esc_html((string) ($snap['slowest_source'] ?? '—'));
		$site = esc_html((string) ($payload['site'] ?? ''));
		$gen  = esc_html((string) ($payload['generated'] ?? ''));
		$ai   = esc_html((string) ($payload['ai'] ?? 'تحلیل هوش مصنوعی هنوز اجرا نشده است.'));

		return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8" />
<title>کارنامه سلامت اسپید‌پالس پرو</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#0f1419;color:#e7ecf3;padding:32px;line-height:1.8}
h1,h2{color:#7dd3c0}
.card{background:#1a222d;border:1px solid #2c3a4a;border-radius:12px;padding:20px;margin:16px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.metric{background:#121820;padding:14px;border-radius:10px}
.metric b{display:block;font-size:1.4rem;color:#f0c674}
</style>
</head>
<body>
<h1>کارنامه سلامت و بهینه‌سازی سایت</h1>
<p>سایت: {$site}<br/>زمان تولید: {$gen}</p>
<div class="card grid">
  <div class="metric">TTFB (ms)<b>{$ttfb}</b></div>
  <div class="metric">تعداد کوئری<b>{$q}</b></div>
  <div class="metric">رم (MB)<b>{$mem}</b></div>
  <div class="metric">کندترین منبع<b>{$slow}</b></div>
</div>
<div class="card">
  <h2>تحلیل هوش مصنوعی</h2>
  <pre style="white-space:pre-wrap">{$ai}</pre>
</div>
<p>این گزارش توسط اسپید‌پالس پرو اولترا تولید شده است.</p>
</body>
</html>
HTML;
	}
}
