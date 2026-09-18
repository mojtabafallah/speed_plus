<?php
/**
 * خروجی گزارش: JSON / HTML-PDF / CSV-Excel + اطلاعات سیستم و Network.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

use SpeedPulsePro\Core\Profiler;
use SpeedPulsePro\Core\RecommendationEngine;
use SpeedPulsePro\Core\SystemInfo;
use SpeedPulsePro\Debug\LogStreamer;
use SpeedPulsePro\WooCommerce\WooSurgeon;

final class ReportExporter
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
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

		$format  = sanitize_key((string) ($_GET['format'] ?? 'json'));
		$payload = $this->buildPayload();

		if ($format === 'csv' || $format === 'excel') {
			$this->sendCsv($payload, $format === 'excel' ? 'speedpulse-network.xlsx.csv' : 'speedpulse-network.csv');
		}

		if ($format === 'pdf' || $format === 'html') {
			nocache_headers();
			header('Content-Type: text/html; charset=utf-8');
			$filename = $format === 'pdf' ? 'speedpulse-report-print.html' : 'speedpulse-report.html';
			header('Content-Disposition: inline; filename="' . $filename . '"');
			echo $this->toHtml($payload, $format === 'pdf');
			exit;
		}

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="speedpulse-report.json"');
		echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		exit;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildPayload(): array
	{
		$snap = get_transient('speedpulse_last_snapshot');
		if (! is_array($snap)) {
			$snap = [];
		}
		$browser = get_transient('speedpulse_browser_metrics');
		$system  = (new SystemInfo())->collect();
		$tips    = (new RecommendationEngine())->build($snap, is_array($browser) ? $browser : null);

		return [
			'title'           => 'کارنامه سلامت اسپید‌پالس پرو',
			'generated'       => wp_date('Y-m-d H:i:s'),
			'site'            => home_url('/'),
			'snapshot'        => $snap,
			'browser'         => is_array($browser) ? $browser : [],
			'system'          => $system,
			'recommendations' => $tips,
			'errors'          => (new LogStreamer())->classify(100000),
			'woo'             => (new WooSurgeon($this->profiler))->cleanupReport(),
			'dom'             => get_transient('speedpulse_dom_last'),
			'memory'          => get_transient('speedpulse_memory_report'),
			'ai'              => get_transient('speedpulse_ai_last_analysis'),
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function sendCsv(array $payload, string $filename): void
	{
		$browser = is_array($payload['browser'] ?? null) ? $payload['browser'] : [];
		$rows = [];
		$rows[] = ['نوع', 'اکشن', 'آدرس/فایل', 'شروع (ms)', 'پایان (ms)', 'مدت', 'مدت (ms)', 'انتظار سرور (ms)', 'حجم (KB)', 'initiator'];

		$resources = [];
		if (! empty($browser['resources']) && is_array($browser['resources'])) {
			$resources = $browser['resources'];
		} elseif (! empty($browser['slowest']) && is_array($browser['slowest'])) {
			$resources = $browser['slowest'];
		}

		foreach ($resources as $r) {
			if (! is_array($r)) {
				continue;
			}
			$start = (float) ($r['start_ms'] ?? 0);
			$dur   = (float) ($r['duration_ms'] ?? 0);
			$rows[] = [
				(string) ($r['type'] ?? ''),
				(string) ($r['action'] ?? ''),
				(string) ($r['url'] ?? $r['name'] ?? ''),
				(string) $start,
				(string) round($start + $dur, 2),
				(string) ($r['human'] ?? Profiler::formatDuration($dur)),
				(string) $dur,
				(string) ($r['waiting_ms'] ?? 0),
				(string) ($r['transfer_kb'] ?? 0),
				(string) ($r['initiator'] ?? ''),
			];
		}

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
		$out = fopen('php://output', 'w');
		if ($out) {
			foreach ($rows as $row) {
				fputcsv($out, $row);
			}
			fclose($out);
		}
		exit;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function toHtml(array $payload, bool $autoPrint = false): string
	{
		$snap = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];
		$browser = is_array($payload['browser'] ?? null) ? $payload['browser'] : [];
		$system = is_array($payload['system'] ?? null) ? $payload['system'] : [];
		$tips = is_array($payload['recommendations'] ?? null) ? $payload['recommendations'] : [];

		$total = esc_html((string) ($snap['total_human'] ?? '—'));
		$browserWall = esc_html((string) ($browser['browser_wall_human'] ?? '—'));
		$site = esc_html((string) ($payload['site'] ?? ''));
		$gen  = esc_html((string) ($payload['generated'] ?? ''));

		$phpVer = esc_html((string) ($system['php']['version'] ?? '—'));
		$wpVer = esc_html((string) ($system['wordpress']['version'] ?? '—'));
		$mem = esc_html((string) ($system['memory']['usage_human'] ?? '—'));
		$memLimit = esc_html((string) ($system['memory']['limit_human'] ?? '—'));
		$cpuLoad = esc_html((string) ($system['cpu']['load_1'] ?? '—'));
		$dbSize = esc_html((string) ($system['database']['size'] ?? '—'));

		$netRows = '';
		$resources = [];
		if (! empty($browser['resources']) && is_array($browser['resources'])) {
			$resources = $browser['resources'];
		} elseif (! empty($browser['slowest']) && is_array($browser['slowest'])) {
			$resources = $browser['slowest'];
		}
		foreach ($resources as $r) {
			if (! is_array($r)) {
				continue;
			}
			$start = (float) ($r['start_ms'] ?? 0);
			$dur = (float) ($r['duration_ms'] ?? 0);
			$netRows .= '<tr>'
				. '<td>' . esc_html((string) ($r['type'] ?? '')) . '</td>'
				. '<td>' . esc_html((string) ($r['action'] ?? '')) . '</td>'
				. '<td dir="ltr" style="text-align:left;word-break:break-all">' . esc_html((string) ($r['url'] ?? $r['name'] ?? '')) . '</td>'
				. '<td>' . esc_html((string) $start) . '</td>'
				. '<td>' . esc_html((string) round($start + $dur, 2)) . '</td>'
				. '<td>' . esc_html((string) ($r['human'] ?? Profiler::formatDuration($dur))) . '</td>'
				. '</tr>';
		}

		$tipHtml = '';
		foreach ($tips as $t) {
			if (! is_array($t)) {
				continue;
			}
			$steps = '';
			foreach ((array) ($t['steps'] ?? []) as $s) {
				$steps .= '<li>' . esc_html((string) $s) . '</li>';
			}
			$tipHtml .= '<div class="card"><h3>' . esc_html((string) ($t['title'] ?? '')) . '</h3><p>'
				. esc_html((string) ($t['why'] ?? '')) . '</p><ol>' . $steps . '</ol></div>';
		}

		$printJs = $autoPrint ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},400)});</script>' : '';

		return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8" />
<title>گزارش اسپید‌پالس پرو</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#0f1419;color:#e7ecf3;padding:24px;line-height:1.8}
h1,h2,h3{color:#7dd3c0}
.card{background:#1a222d;border:1px solid #2c3a4a;border-radius:12px;padding:16px;margin:14px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.metric{background:#121820;padding:12px;border-radius:10px}
.metric b{display:block;font-size:1.2rem;color:#f0c674}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{border:1px solid #2c3a4a;padding:8px;vertical-align:top}
th{background:#121820;color:#7dd3c0}
@media print{body{background:#fff;color:#000} .card,th,td,.metric{background:#fff;border-color:#ccc;color:#000} h1,h2,h3,.metric b{color:#000} .no-print{display:none}}
</style>
</head>
<body>
<p class="no-print"><button onclick="window.print()">چاپ / ذخیره PDF</button></p>
<h1>گزارش سلامت و Network</h1>
<p>سایت: {$site}<br/>زمان تولید: {$gen}<br/>نویسنده گزارش: Mojtaba Fallah</p>
<div class="card grid">
  <div class="metric">زمان مرورگر<b>{$browserWall}</b></div>
  <div class="metric">زمان سرور HTML<b>{$total}</b></div>
  <div class="metric">وردپرس<b>{$wpVer}</b></div>
  <div class="metric">PHP<b>{$phpVer}</b></div>
  <div class="metric">رم مصرفی / سقف<b>{$mem} / {$memLimit}</b></div>
  <div class="metric">Load CPU<b>{$cpuLoad}</b></div>
  <div class="metric">حجم دیتابیس<b>{$dbSize}</b></div>
</div>
<div class="card">
  <h2>درخواست‌های Network</h2>
  <table>
    <thead><tr><th>نوع</th><th>اکشن</th><th>آدرس/فایل</th><th>شروع</th><th>پایان</th><th>مدت</th></tr></thead>
    <tbody>{$netRows}</tbody>
  </table>
</div>
<div class="card"><h2>راهکارها</h2>{$tipHtml}</div>
{$printJs}
</body>
</html>
HTML;
	}
}
