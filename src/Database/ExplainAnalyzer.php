<?php
/**
 * اجرای امن EXPLAIN برای کوئری‌های کند و تشخیص Full Table Scan.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Database;

final class ExplainAnalyzer
{
	/**
	 * @return array{rows:list<array<string,mixed>>,full_scan:bool,warning:string}|null
	 */
	public function analyze(string $sql): ?array
	{
		global $wpdb;

		if (! $this->isSafeSelect($sql)) {
			return null;
		}

		// فقط SELECT — جلوگیری از تزریق دستورات مخرب.
		$explainSql = 'EXPLAIN ' . $sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- کوئری از لاگ داخلی SAVEQUERIES است و قبلاً اعتبارسنجی شده.
		$rows = $wpdb->get_results($explainSql, ARRAY_A);
		if (! is_array($rows)) {
			return null;
		}

		$fullScan = false;
		$warning  = '';
		foreach ($rows as $row) {
			$type  = strtolower((string) ($row['type'] ?? ''));
			$extra = (string) ($row['Extra'] ?? '');
			if (in_array($type, ['all', 'index'], true) || str_contains($extra, 'Using filesort') || str_contains($extra, 'Using temporary')) {
				$fullScan = true;
				$warning  = 'اسکن کامل جدول یا مرتب‌سازی پرهزینه تشخیص داده شد.';
			}
		}

		return [
			'rows'      => $rows,
			'full_scan' => $fullScan,
			'warning'   => $warning,
		];
	}

	private function isSafeSelect(string $sql): bool
	{
		$sql = trim($sql);
		if (! preg_match('/^\s*SELECT\b/i', $sql)) {
			return false;
		}
		// رد دستورات چندگانه و کلمات خطرناک.
		if (preg_match('/;\s*\S/', $sql)) {
			return false;
		}
		if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|LOAD|OUTFILE|DUMPFILE|INTO)\b/i', $sql)) {
			return false;
		}
		return true;
	}
}
