<?php
/**
 * بهینه‌ساز ایندکس یک‌کلیکی برای جداول سنگین وردپرس/ووکامرس.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Database;

final class IndexOptimizer
{
	/**
	 * پیشنهاد ایندکس‌های ترکیبی ایمن.
	 *
	 * @return list<array{table:string,name:string,columns:string,reason:string,exists:bool,sql:string}>
	 */
	public function suggestions(): array
	{
		global $wpdb;

		$candidates = [
			[
				'table'   => $wpdb->postmeta,
				'name'    => 'sp_meta_key_value',
				'columns' => 'meta_key(191), meta_value(32)',
				'reason'  => 'شتاب‌دهی جستجوی meta_key در wp_postmeta',
			],
			[
				'table'   => $wpdb->postmeta,
				'name'    => 'sp_post_meta_key',
				'columns' => 'post_id, meta_key(191)',
				'reason'  => 'کاهش N+1 روی متادیتای پست',
			],
			[
				'table'   => $wpdb->options,
				'name'    => 'sp_autoload_lookup',
				'columns' => 'autoload, option_name',
				'reason'  => 'بهینه‌سازی بارگذاری options با autoload',
			],
			[
				'table'   => $wpdb->prefix . 'woocommerce_order_itemmeta',
				'name'    => 'sp_order_item_meta',
				'columns' => 'meta_key(191), order_item_id',
				'reason'  => 'شتاب جستجوی متای آیتم سفارش ووکامرس',
			],
			[
				'table'   => $wpdb->prefix . 'woocommerce_order_items',
				'name'    => 'sp_order_items_order',
				'columns' => 'order_id, order_item_type',
				'reason'  => 'شتاب بازیابی آیتم‌های سفارش',
			],
		];

		$out = [];
		foreach ($candidates as $c) {
			if (! $this->tableExists($c['table'])) {
				continue;
			}
			$exists = $this->indexExists($c['table'], $c['name']);
			$out[]  = [
				'table'   => $c['table'],
				'name'    => $c['name'],
				'columns' => $c['columns'],
				'reason'  => $c['reason'],
				'exists'  => $exists,
				'sql'     => sprintf(
					'ALTER TABLE `%s` ADD INDEX `%s` (%s)',
					str_replace('`', '``', $c['table']),
					str_replace('`', '``', $c['name']),
					$c['columns']
				),
			];
		}

		return $out;
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function apply(string $indexName): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.'];
		}

		foreach ($this->suggestions() as $s) {
			if ($s['name'] !== $indexName) {
				continue;
			}
			if ($s['exists']) {
				return ['ok' => true, 'message' => 'ایندکس از قبل وجود دارد.'];
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query($s['sql']);
			if (false === $result) {
				return ['ok' => false, 'message' => 'خطا در ایجاد ایندکس: ' . $wpdb->last_error];
			}
			return ['ok' => true, 'message' => 'ایندکس «' . $s['name'] . '» با موفقیت ایجاد شد.'];
		}

		return ['ok' => false, 'message' => 'ایندکس یافت نشد.'];
	}

	private function tableExists(string $table): bool
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		return $found === $table;
	}

	private function indexExists(string $table, string $name): bool
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`', ARRAY_A);
		if (! is_array($rows)) {
			return false;
		}
		foreach ($rows as $row) {
			if (($row['Key_name'] ?? '') === $name) {
				return true;
			}
		}
		return false;
	}
}
