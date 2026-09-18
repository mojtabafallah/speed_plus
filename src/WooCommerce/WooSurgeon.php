<?php
/**
 * جراح عملکرد ووکامرس — سبد، تسویه، درگاه و پاک‌سازی دیتابیس.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\WooCommerce;

use SpeedPulsePro\Core\Profiler;

final class WooSurgeon
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		if (! class_exists('WooCommerce')) {
			return;
		}

		add_action('woocommerce_add_to_cart', [$this, 'markAddToCart'], 1);
		add_action('woocommerce_cart_loaded_from_session', [$this, 'markCartSession'], 1);
		add_action('woocommerce_checkout_process', [$this, 'markCheckout'], 1);
		add_action('woocommerce_before_calculate_totals', [$this, 'markTotals'], 1);
		add_filter('woocommerce_update_order_review_fragments', [$this, 'markFragments'], 99);
		add_action('woocommerce_api_wc_gateway_', [$this, 'markGateway'], 1);

		add_action('http_api_debug', [$this, 'timePaymentHttp'], 20, 5);
	}

	public function markAddToCart(): void
	{
		$this->profiler->addWoo([
			'event'   => 'افزودن به سبد',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
		]);
		$this->profiler->mark('ووکامرس: افزودن به سبد', 'افزونه: woocommerce');
	}

	public function markCartSession(): void
	{
		$this->profiler->addWoo([
			'event'   => 'بارگذاری سبد از نشست',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
		]);
	}

	public function markCheckout(): void
	{
		$this->profiler->addWoo([
			'event'   => 'پردازش تسویه‌حساب',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
		]);
		$this->profiler->mark('ووکامرس: تسویه‌حساب', 'افزونه: woocommerce');
	}

	public function markTotals(): void
	{
		$this->profiler->addWoo([
			'event'   => 'محاسبه جمع کل',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
		]);
	}

	/**
	 * @param array<string, mixed> $fragments
	 * @return array<string, mixed>
	 */
	public function markFragments(array $fragments): array
	{
		$this->profiler->addWoo([
			'event'   => 'Cart Fragments',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
			'keys'    => array_keys($fragments),
		]);
		return $fragments;
	}

	public function markGateway(): void
	{
		$this->profiler->addWoo([
			'event'   => 'فراخوانی درگاه پرداخت',
			'time_ms' => round($this->profiler->elapsedMs(), 2),
		]);
	}

	/**
	 * @param mixed                $response
	 * @param array<string, mixed> $args
	 */
	public function timePaymentHttp($response, string $context, string $class, array $args, string $url): void
	{
		if ($context !== 'response') {
			return;
		}
		$paymentHints = ['payment', 'gateway', 'zarinpal', 'mellat', 'parsian', 'stripe', 'paypal', 'pay'];
		$lower        = strtolower($url);
		$match        = false;
		foreach ($paymentHints as $h) {
			if (str_contains($lower, $h)) {
				$match = true;
				break;
			}
		}
		if (! $match) {
			return;
		}
		$start = (float) ($args['_speedpulse_start'] ?? 0);
		$ms    = $start > 0 ? (microtime(true) - $start) * 1000 : 0;
		$this->profiler->addWoo([
			'event'   => 'درخواست HTTP درگاه/پرداخت',
			'url'     => esc_url_raw($url),
			'time_ms' => round($ms, 2),
		]);
	}

	/**
	 * گزارش پاک‌سازی دیتابیس ووکامرس.
	 *
	 * @return array<string, mixed>
	 */
	public function cleanupReport(): array
	{
		global $wpdb;

		if (! class_exists('WooCommerce')) {
			return ['active' => false, 'message' => 'ووکامرس نصب نیست.'];
		}

		$expiredTransients = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_wc\_%' AND option_value < UNIX_TIMESTAMP()"
		);

		$sessionsTable = $wpdb->prefix . 'woocommerce_sessions';
		$oldSessions   = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessionsTable)) === $sessionsTable) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$oldSessions = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$sessionsTable} WHERE session_expiry < UNIX_TIMESTAMP()"
			);
		}

		$orphans = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL"
		);

		return [
			'active'             => true,
			'expired_transients' => $expiredTransients,
			'expired_sessions'   => $oldSessions,
			'orphan_postmeta'    => $orphans,
		];
	}

	/**
	 * اجرای پاک‌سازی امن با محدودیت تعداد.
	 *
	 * @return array{ok:bool,message:string,deleted:int}
	 */
	public function runCleanup(string $target): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.', 'deleted' => 0];
		}

		global $wpdb;
		$deleted = 0;

		switch ($target) {
			case 'transients':
				$deleted += (int) $wpdb->query(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_wc\_%' AND option_value < UNIX_TIMESTAMP()"
				);
				$deleted += (int) $wpdb->query(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wc\_%'
					 AND option_name NOT IN (
						SELECT REPLACE(option_name, '_timeout', '') FROM (
							SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_wc\_%'
						) t
					 )"
				);
				return ['ok' => true, 'message' => 'ترنزینت‌های منقضی ووکامرس پاک شدند.', 'deleted' => $deleted];

			case 'sessions':
				$table = $wpdb->prefix . 'woocommerce_sessions';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$deleted = (int) $wpdb->query("DELETE FROM {$table} WHERE session_expiry < UNIX_TIMESTAMP() LIMIT 5000");
				}
				return ['ok' => true, 'message' => 'نشست‌های منقضی پاک شدند.', 'deleted' => $deleted];

			case 'orphans':
				$deleted = (int) $wpdb->query(
					"DELETE pm FROM {$wpdb->postmeta} pm
					 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.ID IS NULL
					 LIMIT 2000"
				);
				return ['ok' => true, 'message' => 'متادیتاهای یتیم حذف شدند.', 'deleted' => $deleted];

			default:
				return ['ok' => false, 'message' => 'هدف پاک‌سازی نامعتبر است.', 'deleted' => 0];
		}
	}
}
