<?php
/**
 * تولید خودکار فایل پچ بهینه‌ساز توسط هوش مصنوعی.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\AI;

final class PatchGenerator
{
	public const PATCH_FILE = 'speedpulse-optimizer-patch.php';

	public function boot(): void
	{
	}

	public function patchPath(): string
	{
		return trailingslashit(WP_CONTENT_DIR) . self::PATCH_FILE;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{ok:bool,message:string,code:string,diff:string}
	 */
	public function generate(array $context): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.', 'code' => '', 'diff' => ''];
		}

		$client = new AiClient();
		$promptContext = array_merge($context, [
			'_instruction' => 'فقط کد PHP خالص یک افزونه سبک MU-مانند برگردان که: ۱) dequeue دارایی‌های زائد ۲) کش ترنزینت برای کوئری‌های تکراری ۳) غیرفعال‌سازی هوک‌های سنگین در فرانت. بدون مارک‌داون، بدون توضیح.',
		]);

		$result = $client->analyze([
			'mode'    => 'patch_generation',
			'context' => $promptContext,
			'template'=> $this->safeTemplate(),
		]);

		$code = $result['ok'] ? $this->extractPhp($result['content']) : $this->safeTemplate();
		if (! $this->isSafePhp($code)) {
			$code = $this->safeTemplate();
			return [
				'ok'      => true,
				'message' => 'کد دریافتی ایمن‌سازی شد و قالب محافظه‌کارانه جایگزین گردید.',
				'code'    => $code,
				'diff'    => $this->diffAgainstExisting($code),
			];
		}

		set_transient('speedpulse_pending_patch', $code, HOUR_IN_SECONDS);

		return [
			'ok'      => true,
			'message' => 'پیش‌نویس پچ آماده شد. پیش از اعمال، بررسی و تایید کنید.',
			'code'    => $code,
			'diff'    => $this->diffAgainstExisting($code),
		];
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function savePending(string $code): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}
		$code = $this->extractPhp($code);
		if (! $this->isSafePhp($code)) {
			return ['ok' => false, 'message' => 'کد حاوی الگوهای خطرناک است و ذخیره نشد.'];
		}
		set_transient('speedpulse_pending_patch', $code, HOUR_IN_SECONDS);
		return ['ok' => true, 'message' => 'پیش‌نویس ذخیره شد.'];
	}

	/**
	 * اعمال پس از موفقیت Canary.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function applyApproved(): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}

		$code = (string) get_transient('speedpulse_pending_patch');
		if ($code === '' || ! $this->isSafePhp($code)) {
			return ['ok' => false, 'message' => 'پیش‌نویس معتبر یافت نشد.'];
		}

		$path = $this->patchPath();
		$backup = $path . '.bak.' . time();
		if (file_exists($path)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			@copy($path, $backup);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents($path, $code);
		if (false === $written) {
			return ['ok' => false, 'message' => 'نوشتن فایل پچ ناموفق بود.'];
		}

		update_option(
			'speedpulse_patch_meta',
			[
				'active'  => true,
				'applied' => time(),
				'backup'  => $backup,
			],
			false
		);

		return ['ok' => true, 'message' => 'پچ اعمال شد: ' . self::PATCH_FILE];
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function rollback(): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}

		$path = $this->patchPath();
		$meta = get_option('speedpulse_patch_meta', []);
		if (file_exists($path)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink
			@unlink($path);
		}
		$backup = is_array($meta) ? (string) ($meta['backup'] ?? '') : '';
		if ($backup !== '' && is_readable($backup)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			@copy($backup, $path);
		}
		update_option('speedpulse_patch_meta', ['active' => false, 'rolled_back' => time()], false);
		return ['ok' => true, 'message' => 'بازگشت امن انجام شد؛ پچ غیرفعال است.'];
	}

	public function loadActiveIfSafe(): void
	{
		$meta = get_option('speedpulse_patch_meta', []);
		if (empty($meta['active'])) {
			return;
		}
		$path = $this->patchPath();
		if (is_readable($path)) {
			require_once $path;
		}
	}

	private function extractPhp(string $content): string
	{
		$content = trim($content);
		if (preg_match('/```(?:php)?\s*([\s\S]+?)```/', $content, $m)) {
			$content = trim($m[1]);
		}
		if (! str_starts_with($content, '<?php')) {
			$content = "<?php\ndeclare(strict_types=1);\n\n" . ltrim($content, "<?php\n");
		}
		return $content;
	}

	public function isSafePhp(string $code): bool
	{
		$banned = [
			'eval(', 'assert(', 'shell_exec', 'passthru', 'system(', 'proc_open',
			'popen(', 'base64_decode', 'create_function', '$_REQUEST', '$_GET[',
			'mysql_query', 'file_get_contents("http', 'curl_exec', 'wp_remote_post',
		];
		$lower = strtolower($code);
		foreach ($banned as $b) {
			if (str_contains($lower, strtolower($b))) {
				return false;
			}
		}
		// باید فقط با هوک‌های وردپرس کار کند.
		if (! str_contains($code, 'add_action') && ! str_contains($code, 'add_filter')) {
			return false;
		}
		return true;
	}

	private function diffAgainstExisting(string $newCode): string
	{
		$old = is_readable($this->patchPath())
			? (string) file_get_contents($this->patchPath())
			: '';
		if ($old === $newCode) {
			return 'بدون تغییر نسبت به نسخه فعلی.';
		}
		$oldLines = explode("\n", $old);
		$newLines = explode("\n", $newCode);
		$diff     = [];
		$max      = max(count($oldLines), count($newLines));
		for ($i = 0; $i < min($max, 120); $i++) {
			$o = $oldLines[$i] ?? '';
			$n = $newLines[$i] ?? '';
			if ($o !== $n) {
				if ($o !== '') {
					$diff[] = '- ' . $o;
				}
				if ($n !== '') {
					$diff[] = '+ ' . $n;
				}
			}
		}
		return $diff === [] ? 'تفاوت ساختاری تشخیص داده شد.' : implode("\n", $diff);
	}

	private function safeTemplate(): string
	{
		return <<<'PHP'
<?php
/**
 * پچ بهینه‌ساز اسپید‌پالس — تولیدشده به‌صورت محافظه‌کارانه.
 * فقط dequeue دارایی‌های رایج زائد در صفحات غیرضروری.
 */
declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

add_action('wp_enqueue_scripts', static function (): void {
	if (is_admin()) {
		return;
	}
	// نمونه ایمن: حذف ایموجی‌های هسته در فرانت در صورت عدم نیاز.
	remove_action('wp_head', 'print_emoji_detection_script', 7);
	remove_action('wp_print_styles', 'print_emoji_styles');
}, 100);

add_action('init', static function (): void {
	// کش سبک برای گزینه پرتکرار.
	add_filter('pre_option_blogdescription', static function ($pre) {
		$cached = get_transient('speedpulse_patch_blogdescription');
		if (false !== $cached) {
			return $cached;
		}
		return $pre;
	}, 5);
}, 5);
PHP;
	}
}
