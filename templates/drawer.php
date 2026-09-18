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

$exportJson  = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=json'), 'speedpulse_export');
$exportHtml  = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=html'), 'speedpulse_export');
$exportPdf   = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=pdf'), 'speedpulse_export');
$exportExcel = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=excel'), 'speedpulse_export');
$exportCsv   = wp_nonce_url(admin_url('admin-post.php?action=speedpulse_export&format=csv'), 'speedpulse_export');

/**
 * دکمه خروجی عکس مشترک هر تب.
 *
 * @param string $panel
 */
$exportImgBtn = static function (string $panel): void {
	echo '<div class="sp-tab-toolbar sp-no-capture">';
	echo '<button type="button" class="button sp-export-img" data-export-panel="' . esc_attr($panel) . '">خروجی عکس این بخش</button>';
	echo '</div>';
};
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
			<button type="button" class="is-active" data-tab="timeline">
				<span class="sp-tab-label">خط زمان</span>
				<span class="sp-tab-metric" data-tab-metric="timeline">—</span>
			</button>
			<button type="button" data-tab="tips">
				<span class="sp-tab-label">راهکارها</span>
				<span class="sp-tab-metric" data-tab-metric="tips">—</span>
			</button>
			<button type="button" data-tab="system">
				<span class="sp-tab-label">سیستم</span>
				<span class="sp-tab-metric" data-tab-metric="system">—</span>
			</button>
			<button type="button" data-tab="live">
				<span class="sp-tab-label">رم / CPU زنده</span>
				<span class="sp-tab-metric" data-tab-metric="live">—</span>
			</button>
			<button type="button" data-tab="queries">
				<span class="sp-tab-label">کوئری‌ها</span>
				<span class="sp-tab-metric" data-tab-metric="queries">—</span>
			</button>
			<button type="button" data-tab="sources">
				<span class="sp-tab-label">سهم منابع</span>
				<span class="sp-tab-metric" data-tab-metric="sources">—</span>
			</button>
			<button type="button" data-tab="errors">
				<span class="sp-tab-label">لاگ خطاها</span>
				<span class="sp-tab-metric" data-tab-metric="errors">—</span>
			</button>
			<button type="button" data-tab="woo">
				<span class="sp-tab-label">جراح ووکامرس</span>
				<span class="sp-tab-metric" data-tab-metric="woo">—</span>
			</button>
			<button type="button" data-tab="dom">
				<span class="sp-tab-label">DOM</span>
				<span class="sp-tab-metric" data-tab-metric="dom">—</span>
			</button>
			<button type="button" data-tab="tools">
				<span class="sp-tab-label">ابزارها</span>
				<span class="sp-tab-metric" data-tab-metric="tools">—</span>
			</button>
			<button type="button" data-tab="ai">
				<span class="sp-tab-label">هوش مصنوعی</span>
				<span class="sp-tab-metric" data-tab-metric="ai">—</span>
			</button>
			<button type="button" data-tab="total" class="sp-tab-total">
				<span class="sp-tab-label">جمع کل</span>
				<span class="sp-tab-metric" data-tab-metric="total">—</span>
			</button>
		</nav>

		<div class="speedpulse-drawer__body">
			<section class="speedpulse-tab is-active" data-panel="timeline">
				<?php $exportImgBtn('timeline'); ?>
				<h3>زمان واقعی صفحه</h3>
				<div id="sp-browser" class="sp-browser"></div>
				<div id="sp-browser-detail" class="sp-browser-detail" hidden></div>
				<h3>خلاصه زمان سرور (HTML)</h3>
				<div id="sp-breakdown" class="sp-breakdown"></div>
				<h3>آبشار زمان اجرای PHP</h3>
				<div id="sp-timeline" class="sp-waterfall"></div>
			</section>

			<section class="speedpulse-tab" data-panel="tips">
				<?php $exportImgBtn('tips'); ?>
				<h3>راهکارهای پیشنهادی برای کندی‌ها</h3>
				<p class="sp-meta">بر اساس داده سرور + Network مرورگر، برای هر مشکل یک سناریوی عملی پیشنهاد می‌شود.</p>
				<div class="sp-actions sp-no-capture">
					<button type="button" class="button button-primary" id="sp-refresh-tips">به‌روزرسانی راهکارها</button>
				</div>
				<div id="sp-tips"></div>
			</section>

			<section class="speedpulse-tab" data-panel="system">
				<?php $exportImgBtn('system'); ?>
				<h3>اطلاعات کلی سرور و وردپرس</h3>
				<p class="sp-meta">نسخه PHP، وردپرس، دیتابیس، دیسک، افزونه‌ها، OPcache و وضعیت کش شیء.</p>
				<div class="sp-actions sp-no-capture">
					<button type="button" class="button button-primary" id="sp-refresh-system">بازخوانی اطلاعات سیستم</button>
				</div>
				<div id="sp-system"></div>
			</section>

			<section class="speedpulse-tab" data-panel="live">
				<?php $exportImgBtn('live'); ?>
				<h3>رم و CPU بلادرنگ</h3>
				<p class="sp-meta">نمونه‌برداری هر ۲ ثانیه از حافظه PHP، Load Average و سهم منابع آخرین اسنپ‌شات.</p>
				<div class="sp-live-controls">
					<span id="sp-live-status" class="sp-badge ok">آماده</span>
					<span id="sp-live-clock" class="sp-meta">—</span>
				</div>
				<div id="sp-live"></div>
			</section>

			<section class="speedpulse-tab" data-panel="queries">
				<?php $exportImgBtn('queries'); ?>
				<h3>کوئری‌های SQL</h3>
				<div id="sp-queries"></div>
				<h4>ایندکس‌های پیشنهادی</h4>
				<div id="sp-indexes"></div>
			</section>

			<section class="speedpulse-tab" data-panel="sources">
				<?php $exportImgBtn('sources'); ?>
				<h3>سهم CPU / هوک / کوئری</h3>
				<div id="sp-sources"></div>
				<h4>درخواست‌های شبکه</h4>
				<div id="sp-network"></div>
			</section>

			<section class="speedpulse-tab" data-panel="errors">
				<?php $exportImgBtn('errors'); ?>
				<h3>تحلیل debug.log</h3>
				<div class="sp-actions sp-no-capture">
					<button type="button" class="button" id="sp-refresh-log">بازخوانی لاگ</button>
					<button type="button" class="button" id="sp-clear-log">پاک‌سازی لاگ</button>
				</div>
				<div id="sp-errors"></div>
			</section>

			<section class="speedpulse-tab" data-panel="woo">
				<?php $exportImgBtn('woo'); ?>
				<h3>جراح ووکامرس</h3>
				<div id="sp-woo"></div>
				<div class="sp-actions sp-no-capture">
					<button type="button" class="button" data-woo-clean="transients">پاک‌سازی ترنزینت‌ها</button>
					<button type="button" class="button" data-woo-clean="sessions">پاک‌سازی نشست‌ها</button>
					<button type="button" class="button" data-woo-clean="orphans">حذف متای یتیم</button>
				</div>
			</section>

			<section class="speedpulse-tab" data-panel="dom">
				<?php $exportImgBtn('dom'); ?>
				<h3>درخت DOM و مسدودکننده‌های رندر</h3>
				<div id="sp-dom"></div>
			</section>

			<section class="speedpulse-tab" data-panel="tools">
				<?php $exportImgBtn('tools'); ?>
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
				<p class="sp-meta">جدول درخواست‌های Network (فایل، شروع، پایان، مدت) + اطلاعات سیستم.</p>
				<p class="sp-export-btns">
					<a class="button button-primary" href="<?php echo esc_url($exportExcel); ?>">دانلود Excel (CSV)</a>
					<a class="button button-primary" href="<?php echo esc_url($exportPdf); ?>" target="_blank" rel="noopener">چاپ / PDF</a>
					<a class="button" href="<?php echo esc_url($exportCsv); ?>">CSV خام</a>
					<a class="button" href="<?php echo esc_url($exportHtml); ?>">HTML</a>
					<a class="button" href="<?php echo esc_url($exportJson); ?>">JSON</a>
				</p>
			</section>

			<section class="speedpulse-tab" data-panel="ai">
				<?php $exportImgBtn('ai'); ?>
				<h3>کالبدشکافی هوش مصنوعی</h3>
				<div class="sp-actions sp-no-capture">
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

			<section class="speedpulse-tab" data-panel="total">
				<?php $exportImgBtn('total'); ?>
				<h3>جمع کل مصرف‌ها</h3>
				<p class="sp-meta">خلاصه یک‌جا از زمان مرورگر، سرور، کوئری، شبکه، رم و سایر بخش‌ها.</p>
				<div id="sp-total"></div>
			</section>
		</div>
	</aside>
</div>
