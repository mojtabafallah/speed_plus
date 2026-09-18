<?php
/**
 * موتور پیشنهاد راهکار برای هر نوع کندی (آفلاین، بدون نیاز به AI).
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class RecommendationEngine
{
	/**
	 * @param array<string, mixed>      $snapshot
	 * @param array<string, mixed>|null $browser
	 * @return list<array{id:string,severity:string,title:string,why:string,steps:list<string>,related:string}>
	 */
	public function build(array $snapshot, ?array $browser = null): array
	{
		$out = [];

		$serverMs = (float) ($snapshot['total_ms'] ?? $snapshot['ttfb_ms'] ?? 0);
		$queryCount = (int) ($snapshot['query_count'] ?? 0);
		$queries = is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [];
		$dups = is_array($snapshot['duplicates'] ?? null) ? $snapshot['duplicates'] : [];
		$network = is_array($snapshot['network'] ?? null) ? $snapshot['network'] : [];
		$attribution = is_array($snapshot['attribution'] ?? null) ? $snapshot['attribution'] : [];
		$breakdown = is_array($snapshot['time_breakdown']['items'] ?? null) ? $snapshot['time_breakdown']['items'] : [];

		$queryMs = 0.0;
		$slowQueries = 0;
		$nPlus = 0;
		$fullScan = 0;
		foreach ($queries as $q) {
			$queryMs += (float) ($q['time_ms'] ?? 0);
			if (! empty($q['slow'])) {
				$slowQueries++;
			}
			if (! empty($q['n_plus_one'])) {
				$nPlus++;
			}
			if (! empty($q['explain']['full_scan'])) {
				$fullScan++;
			}
		}

		if ($serverMs >= 1500) {
			$out[] = $this->item(
				'server-slow',
				'critical',
				'زمان تولید HTML در سرور بالاست',
				'درخواست اصلی حدود ' . Profiler::formatDuration($serverMs) . ' طول کشیده؛ قبل از رسیدن JS/REST صفحه دیر آماده می‌شود.',
				[
					'افزونه‌های سنگین را موقتاً غیرفعال و دوباره اندازه بگیرید تا مقصر مشخص شود.',
					'Object Cache دائمی (Redis/Memcached) و Page Cache را فعال کنید.',
					'در پیشخوان، صفحه‌هایی مثل لیست محصولات را با فیلتر کمتر و بدون متاهای اضافی تست کنید.',
				],
				'timeline'
			);
		}

		if ($queryMs >= 300 || $slowQueries >= 3) {
			$out[] = $this->item(
				'queries-slow',
				'critical',
				'گلوگاه دیتابیس / کوئری‌های کند',
				'مجموع زمان SQL حدود ' . Profiler::formatDuration($queryMs) . ' است' . ($slowQueries ? " ({$slowQueries} کوئری کند)" : '') . '.',
				[
					'تب «کوئری‌ها» را باز کنید و کندترین‌ها را ببینید؛ معمولاً postmeta و options مقصرند.',
					'از بخش ایندکس یک‌کلیکی برای جداول سنگین استفاده کنید.',
					'autoloadهای غیرضروری در wp_options را کم کنید و ترنزینت‌های منقضی را پاک کنید.',
				],
				'queries'
			);
		}

		if (count($dups) >= 2 || $nPlus >= 1) {
			$out[] = $this->item(
				'n-plus-one',
				'warn',
				'کوئری تکراری یا الگوی N+1',
				$nPlus ? 'الگوی N+1 تشخیص داده شد.' : 'چند کوئری تکراری ثبت شده است.',
				[
					'نتایج پرتکرار را با Transient یا object cache نگه دارید.',
					'در حلقه‌ها به‌جای get_post_meta تکی، از پرس‌وجوی گروهی یا hydrate استفاده کنید.',
					'اگر ووکامرس است، HPOS را فعال و افزونه‌های گزارش‌گیر سنگین را سبک کنید.',
				],
				'queries'
			);
		}

		if ($fullScan >= 1) {
			$out[] = $this->item(
				'full-scan',
				'warn',
				'اسکن کامل جدول (Full Table Scan)',
				'حداقل یک کوئری کند بدون استفاده مؤثر از ایندکس دیده شد.',
				[
					'ایندکس ترکیبی پیشنهادی پلاگین را برای همان جدول اعمال کنید.',
					'شرط WHERE را روی ستون‌های ایندکس‌دار بنویسید و از LIKE \'%...\' اول‌آزاد پرهیز کنید.',
				],
				'queries'
			);
		}

		$blockingNet = 0.0;
		$blockingCount = 0;
		foreach ($network as $n) {
			if (! empty($n['blocking'])) {
				$blockingNet += (float) ($n['time_ms'] ?? 0);
				$blockingCount++;
			}
		}
		if ($blockingNet >= 200 || $blockingCount >= 2) {
			$out[] = $this->item(
				'server-http',
				'warn',
				'فراخوانی HTTP مسدودکننده در سرور',
				'در حین ساخت صفحه، ' . $blockingCount . ' درخواست wp_remote_* حدود ' . Profiler::formatDuration($blockingNet) . ' وقت گرفته.',
				[
					'APIهای خارجی را به صف ناهمگام / Action Scheduler منتقل کنید.',
					'timeout را کاهش دهید و نتیجه را کش کنید.',
					'در فرانت، تا جای ممکن blocking را false کنید.',
				],
				'sources'
			);
		}

		$topName = '';
			$topCpu  = 0.0;
			foreach ($attribution as $name => $row) {
				$topName = (string) $name;
				$topCpu  = (float) ($row['cpu'] ?? 0);
				break;
			}
			if ($topCpu >= 200 && $topName !== '' && $topName !== 'هسته وردپرس') {
				$out[] = $this->item(
					'top-plugin',
					'warn',
					'منبع کند: ' . $topName,
					'این منبع بیشترین سهم را در پروفایل سرور داشته است.',
					[
						'آن افزونه/قالب را موقتاً خاموش و اختلاف زمان را بسنجید.',
						'اسکریپت‌ها و استایل‌های آن را فقط در صفحات لازم enqueue کنید.',
						'نسخه افزونه و سازگاری با PHP/ووکامرس را بررسی کنید.',
					],
					'sources'
				);
			}
		}

		// مرورگر / Network
		if (is_array($browser)) {
			$wall = (float) ($browser['browser_wall_ms'] ?? 0);
			$slowest = is_array($browser['slowest'] ?? null) ? $browser['slowest'] : [];
			$byType = is_array($browser['by_type'] ?? null) ? $browser['by_type'] : [];

			if ($wall >= 8000) {
				$out[] = $this->item(
					'browser-wall',
					'critical',
					'زمان واقعی صفحه از دید مرورگر خیلی بالاست',
					'تجربه کاربر حدود ' . Profiler::formatDuration($wall) . ' طول کشیده (شامل Network).',
					[
						'کندترین ردیف‌های Network را در پنل ببینید؛ معمولاً wp-json ووکامرس یا load-scripts است.',
						'تعداد افزونه‌های ادمین که REST پشت‌سرهم می‌زنند را کم کنید.',
						'در محیط لوکال، Xdebug/آنتی‌ویروس/دیسک کند هم زمان را باد می‌کند.',
					],
					'timeline'
				);
			}

			foreach ($byType as $t) {
				$key = (string) ($t['key'] ?? '');
				$max = (float) ($t['max_ms'] ?? 0);
				if ($max < 3000) {
					continue;
				}
				if ($key === 'rest') {
					$out[] = $this->item(
						'rest-slow',
						'critical',
						'درخواست‌های REST API کند هستند',
						'حداقل یک wp-json حدود ' . Profiler::formatDuration($max) . ' طول کشیده.',
						[
							'در ووکامرس: HPOS را فعال کنید و ایندکس جداول سفارش را بررسی کنید.',
							'ویجت‌ها/آنبوردینگ/نوتیفیکیشن‌های wc-admin را سبک یا غیرفعال کنید.',
							'کرون و Action Scheduler معوق را خالی کنید تا REST سبک‌تر شود.',
							'اگر فقط در لوکال کند است، اول بدون Xdebug اندازه بگیرید.',
						],
						'timeline'
					);
				} elseif ($key === 'bundle') {
					$out[] = $this->item(
						'bundle-slow',
						'warn',
						'load-scripts / load-styles کند است',
						'باندل اسکریپت/استایل ادمین حدود ' . Profiler::formatDuration($max) . ' زمان برده.',
						[
							'اسکریپت‌های غیرضروری ادمین را dequeue کنید.',
							'CONCATENATE_SCRIPTS را در صورت تداخل بررسی کنید؛ گاهی جدا کردن فایل‌ها در لوکال سریع‌تر است.',
							'کش مرورگر را برای تست واقعی روشن بگذارید (غیر از Disable cache).',
						],
						'sources'
					);
				} elseif ($key === 'ajax') {
					$out[] = $this->item(
						'ajax-slow',
						'warn',
						'admin-ajax.php کند است',
						'حداقل یک AJAX حدود ' . Profiler::formatDuration($max) . ' طول کشیده.',
						[
							'هوک‌های ajax اکشن مربوطه را پیدا و پروفایل کنید.',
							'کارهای سنگین را به REST یا صف پس‌زمینه منتقل کنید.',
							'Heartbeat ادمین را محدود کنید اگر تداخل ایجاد می‌کند.',
						],
						'timeline'
					);
				}
			}

			// پیشنهاد موردی برای ۳ کندترین URL
			$i = 0;
			foreach ($slowest as $row) {
				if ($i >= 3) {
					break;
				}
				$dur = (float) ($row['duration_ms'] ?? 0);
				if ($dur < 4000) {
					continue;
				}
				$name = (string) ($row['name'] ?? $row['url'] ?? 'منبع');
				$out[] = $this->item(
					'slow-url-' . $i,
					$dur >= 10000 ? 'critical' : 'warn',
					'کندی موردی: ' . $this->short($name, 70),
					'این درخواست حدود ' . Profiler::formatDuration($dur) . ' طول کشیده.',
					$this->tipsForUrl($name, (string) ($row['type'] ?? '')),
					'timeline'
				);
				$i++;
			}
		}

		if ($out === []) {
			$out[] = $this->item(
				'ok',
				'ok',
				'کندی بحرانی دیده نشد',
				'با داده‌های فعلی، مورد بحرانی ثبت نشده است؛ برای دقت بیشتر یک بار با ضبط روشن صفحه را کامل لود کنید.',
				[
					'مانیتورینگ را روشن نگه دارید و همان صفحه کند را ۱۵–۳۵ ثانیه باز بگذارید.',
					'اگر باز هم کندی حس می‌کنید، تب Network مرورگر را با Disable cache مقایسه کنید.',
				],
				'timeline'
			);
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function tipsForUrl(string $url, string $type): array
	{
		$u = strtolower($url);
		if (strpos($u, 'wc-admin') !== false || strpos($u, 'wc-analytics') !== false) {
			return [
				'داشبورد و آنالیتیکس ووکامرس را سبک کنید یا ویجت‌های غیرضروری را مخفی کنید.',
				'ایندکس/بهینه‌سازی جداول سفارش و محصول را انجام دهید.',
				'افزونه‌های گزارش‌گیری موازی را موقتاً خاموش کنید.',
			];
		}
		if (strpos($u, 'onboarding/tasks') !== false) {
			return [
				'اگر فروشگاه راه‌اندازی شده، ویزارد آنبوردینگ ووکامرس را کامل/مخفی کنید تا این REST تکرار نشود.',
			];
		}
		if (strpos($u, 'load-scripts.php') !== false) {
			return [
				'تعداد هندل‌های اسکریپت ادمین را کم کنید.',
				'افزونه‌هایی که در همه صفحات ادمین JS می‌ریزند را محدود به صفحه خودشان کنید.',
			];
		}
		if (strpos($u, 'admin-ajax.php') !== false) {
			return [
				'اکشن AJAX را در لاگ/پروفایلر پیدا کنید و کوئری داخلش را بهینه کنید.',
			];
		}
		if ($type === 'rest' || strpos($u, '/wp-json/') !== false) {
			return [
				'مجوز و کش پاسخ REST را بررسی کنید؛ پاسخ‌های تکراری را Transient کنید.',
				'پرداخت‌های سنگین را از request کاربر جدا و به کرون بسپارید.',
			];
		}
		return [
			'این URL را در تب Network باز کنید و Timing (Waiting/TTFB) را ببینید.',
			'اگر Waiting بالاست، گلوگاه سمت سرور/دیتابیس است؛ اگر Content Download بالاست، حجم پاسخ را کم کنید.',
		];
	}

	/**
	 * @param list<string> $steps
	 * @return array{id:string,severity:string,title:string,why:string,steps:list<string>,related:string}
	 */
	private function item(string $id, string $severity, string $title, string $why, array $steps, string $related): array
	{
		return [
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'why'      => $why,
			'steps'    => $steps,
			'related'  => $related,
		];
	}

	private function short(string $text, int $max): string
	{
		if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
			return mb_substr($text, 0, $max) . '…';
		}
		if (strlen($text) > $max) {
			return substr($text, 0, $max) . '…';
		}
		return $text;
	}
}
