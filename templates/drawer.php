<?php
/**
 * قالب پنل کشویی تاریک — کاملاً فارسی و راست‌چین.
 *
 * @var \SpeedPulsePro\Core\Profiler $profiler
 * @package SpeedPulsePro
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

$exportJson = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=json'), 'speedpulse_export');
$exportHtml = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=html'), 'speedpulse_export');
?>
<div id="speedpulse-drawer" class="speedpulse-drawer" aria-hidden="true" dir="rtl">
	<div class="speedpulse-drawer__backdrop" data-sp-close></div>
	<aside class="speedpulse-drawer__panel" role="dialog" aria-label="پنل اسپید‌پالس پرو">
		<header class="speedpulse-drawer__head">
			<div>
				<strong class="speedpulse-brand">اسپید‌پالس پرو اولترا</strong>
				<span class="speedpulse-sub">مانیتورینگ بلادرنگ و جراحی عملکرد</span>
			</div>
			<button type="button" class="speedpulse-icon-btn" data-sp-close aria-label="بستن">×</button>
		</header>

		<nav class="speedpulse-tabs" role="tablist">
			<button type="button" class="is-active" data-tab="timeline">خط زمان</button>
			<button type="button" data-tab="tips">راهکارها</button>
			<button type="button" data-tab="queries">کوئری‌ها</button>
			<button type="button" data-tab="sources">سهم منابع</button>
			<button type="button" data-tab="errors">لاگ خطاها</button>
			<button type="button" data-tab="woo">جراح ووکامرس</button>
			<button type="button" data-tab="dom">DOM</button>
			<button type="button" data-tab="tools">ابزارها</button>
			<button type="button" data-tab="ai">هوش مصنوعی</button>
		</nav>

		<div class="speedpulse-drawer__body">
			<section class="speedpulse-tab is-active" data-panel="timeline">
				<h3>زمان واقعی صفحه</h3>
				<div id="sp-browser" class="sp-browser"></div>
				<h3>خلاصه زمان سرور (HTML)</h3>
				<div id="sp-breakdown" class="sp-breakdown"></div>
				<h3>آبشار زمان اجرای PHP</h3>
				<div id="sp-timeline" class="sp-waterfall"></div>
			</section>

			<section class="speedpulse-tab" data-panel="tips">
				<h3>راهکارهای پیشنهادی برای کندی‌ها</h3>
				<p class="sp-meta">بر اساس داده سرور + Network مرورگر، برای هر مشکل یک سناریوی عملی پیشنهاد می‌شود.</p>
				<div class="sp-actions">
					<button type="button" class="button button-primary" id="sp-refresh-tips">به‌روزرسانی راهکارها</button>
				</div>
				<div id="sp-tips"></div>
			</section>

			<section class="speedpulse-tab" data-panel="queries">
				<h3>کوئری‌های SQL</h3>
				<div id="sp-queries"></div>
				<h4>ایندکس‌های پیشنهادی</h4>
				<div id="sp-indexes"></div>
			</section>

			<section class="speedpulse-tab" data-panel="sources">
				<h3>سهم CPU / هوک / کوئری</h3>
				<div id="sp-sources"></div>
				<h4>درخواست‌های شبکه</h4>
				<div id="sp-network"></div>
			</section>

			<section class="speedpulse-tab" data-panel="errors">
				<h3>تحلیل debug.log</h3>
				<div class="sp-actions">
					<button type="button" class="button" id="sp-refresh-log">بازخوانی لاگ</button>
					<button type="button" class="button" id="sp-clear-log">پاک‌سازی لاگ</button>
				</div>
				<div id="sp-errors"></div>
			</section>

			<section class="speedpulse-tab" data-panel="woo">
				<h3>جراح ووکامرس</h3>
				<div id="sp-woo"></div>
				<div class="sp-actions">
					<button type="button" class="button" data-woo-clean="transients">پاک‌سازی ترنزینت‌ها</button>
					<button type="button" class="button" data-woo-clean="sessions">پاک‌سازی نشست‌ها</button>
					<button type="button" class="button" data-woo-clean="orphans">حذف متای یتیم</button>
				</div>
			</section>

			<section class="speedpulse-tab" data-panel="dom">
				<h3>درخت DOM و مسدودکننده‌های رندر</h3>
				<div id="sp-dom"></div>
			</section>

			<section class="speedpulse-tab" data-panel="tools">
				<h3>تست فشار داخلی</h3>
				<div class="sp-inline-form">
					<label>کاربر همزمان <input type="number" id="sp-stress-c" value="50" min="5" max="200" /></label>
					<label>مدت (ثانیه) <input type="number" id="sp-stress-d" value="5" min="1" max="15" /></label>
					<button type="button" class="button button-primary" id="sp-stress-run">اجرای تست</button>
				</div>
				<div id="sp-stress-result"></div>

				<h3>خزش‌گر سایت</h3>
				<div class="sp-actions">
					<button type="button" class="button" data-crawl="start">شروع</button>
					<button type="button" class="button" data-crawl="pause">توقف</button>
					<button type="button" class="button" data-crawl="resume">ادامه</button>
					<button type="button" class="button" data-crawl="state">وضعیت</button>
				</div>
				<div id="sp-crawler"></div>
				<div class="sp-progress"><div id="sp-crawler-bar"></div></div>

				<h3>خروجی گزارش</h3>
				<p>
					<a class="button" href="<?php echo esc_url($exportHtml); ?>">دانلود HTML</a>
					<a class="button" href="<?php echo esc_url($exportJson); ?>">دانلود JSON</a>
				</p>
			</section>

			<section class="speedpulse-tab" data-panel="ai">
				<h3>کالبدشکافی هوش مصنوعی</h3>
				<div class="sp-actions">
					<button type="button" class="button button-primary" id="sp-ai-run">تحلیل جامع فارسی</button>
					<button type="button" class="button" id="sp-patch-gen">تولید پچ بهینه‌ساز</button>
					<button type="button" class="button" id="sp-patch-canary">Canary + اعمال امن</button>
					<button type="button" class="button" id="sp-patch-rollback">بازگشت امن</button>
				</div>
				<pre id="sp-ai-out" class="sp-code"></pre>
				<label class="sp-label">ویرایش پچ قبل از اعمال</label>
				<textarea id="sp-patch-code" class="sp-codearea" rows="16" dir="ltr"></textarea>
				<pre id="sp-patch-diff" class="sp-code"></pre>
				<button type="button" class="button" id="sp-patch-save">ذخیره پیش‌نویس</button>
			</section>
		</div>
	</aside>
</div>
