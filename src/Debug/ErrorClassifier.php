<?php
/**
 * دسته‌بندی هوشمند خطاهای PHP و راهنمای اصلاح آفلاین.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Debug;

final class ErrorClassifier
{
	/**
	 * @return array{type:string,message:string,file:string,line:int,raw:string}
	 */
	public function parseLine(string $line): array
	{
		$type = 'دیگر';
		if (stripos($line, 'Fatal error') !== false || stripos($line, 'PHP Fatal') !== false) {
			$type = 'Fatal';
		} elseif (stripos($line, 'Deprecated') !== false) {
			$type = 'Deprecated';
		} elseif (stripos($line, 'Warning') !== false) {
			$type = 'Warning';
		} elseif (stripos($line, 'Notice') !== false) {
			$type = 'Notice';
		}

		$file = '';
		$num  = 0;
		if (preg_match('/in (.+?) on line (\d+)/', $line, $m)) {
			$file = $m[1];
			$num  = (int) $m[2];
		}

		$msg = $line;
		if (preg_match('/:\s+(.+?)(\s+in\s+|$)/', $line, $m2)) {
			$msg = trim($m2[1]);
		}

		return [
			'type'    => $type,
			'message' => $msg,
			'file'    => $file,
			'line'    => $num,
			'raw'     => $line,
		];
	}

	/**
	 * @param array{type:string,message:string,file:string,line:int} $error
	 */
	public function offlineFixHint(array $error): string
	{
		$msg = $error['message'];
		return match (true) {
			str_contains($msg, 'Allowed memory size') => 'حد حافظه PHP پر شده است. مصرف حافظه افزونه‌ها را بررسی کنید، کوئری‌های حجیم را صفحه‌بندی کنید و در صورت نیاز memory_limit را افزایش دهید.',
			str_contains($msg, 'Maximum execution time') => 'زمان اجرای اسکریپت تمام شده. پردازش‌های سنگین را به WP-Cron یا صف ناهمگام منتقل کنید.',
			str_contains($msg, 'Undefined array key') || str_contains($msg, 'Undefined index') => 'قبل از دسترسی، وجود کلید آرایه را با isset()/array_key_exists() بررسی کنید.',
			str_contains($msg, 'Trying to access array offset on') => 'مقدار ممکن است null باشد؛ قبل از اندیس‌گذاری، نوع داده را اعتبارسنجی کنید.',
			str_contains($msg, 'Call to undefined function') => 'تابع فراخوانی‌شده لود نشده؛ وابستگی افزونه یا ترتیب هوک را بررسی کنید.',
			str_contains($msg, 'Call to a member function') && str_contains($msg, 'on null') => 'آبجکت null است؛ قبل از فراخوانی متد، وجود نمونه را بررسی کنید.',
			$error['type'] === 'Deprecated' => 'API قدیمی است؛ طبق مستندات نسخه فعلی وردپرس/PHP جایگزین کنید تا در نسخه‌های بعدی از کار نیفتد.',
			default => 'استک‌تریس و فایل منبع را بررسی کنید؛ ورودی‌ها را اعتبارسنجی و شرط‌های مرزی را پوشش دهید.',
		};
	}
}
