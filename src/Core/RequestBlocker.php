<?php
/**
 * بلاک آزمایشی درخواست‌های Network (کلاینت + HTTP سرور).
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class RequestBlocker
{
	public const META_KEY = 'speedpulse_blocked_requests';

	public function boot(): void
	{
		add_filter('pre_http_request', [$this, 'filterHttp'], 1, 3);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function getList(int $userId = 0): array
	{
		if ($userId <= 0) {
			$userId = get_current_user_id();
		}
		if ($userId <= 0) {
			return [];
		}
		$raw = get_user_meta($userId, self::META_KEY, true);
		if (! is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $row) {
			if (! is_array($row)) {
				continue;
			}
			$clean = self::sanitizeItem($row);
			if ($clean !== null) {
				$out[] = $clean;
			}
		}
		return array_values($out);
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return list<array<string, mixed>>
	 */
	public static function saveList(array $items, int $userId = 0): array
	{
		if ($userId <= 0) {
			$userId = get_current_user_id();
		}
		$clean = [];
		foreach ($items as $row) {
			if (! is_array($row)) {
				continue;
			}
			$item = self::sanitizeItem($row);
			if ($item === null) {
				continue;
			}
			$clean[$item['id']] = $item;
			if (count($clean) >= 80) {
				break;
			}
		}
		$list = array_values($clean);
		update_user_meta($userId, self::META_KEY, $list);
		return $list;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public static function sanitizeItem(array $row): ?array
	{
		$kind = sanitize_key((string) ($row['kind'] ?? 'url'));
		if (! in_array($kind, ['url', 'action', 'type', 'pattern'], true)) {
			$kind = 'url';
		}
		$url = esc_url_raw((string) ($row['url'] ?? ''));
		$pattern = sanitize_text_field((string) ($row['pattern'] ?? ''));
		$action = sanitize_key((string) ($row['action'] ?? ''));
		$type = sanitize_key((string) ($row['type'] ?? ''));
		$label = sanitize_text_field((string) ($row['label'] ?? ''));
		$scope = sanitize_key((string) ($row['scope'] ?? 'both'));
		if (! in_array($scope, ['client', 'server', 'both'], true)) {
			$scope = 'both';
		}

		if ($kind === 'action' && $action === '') {
			return null;
		}
		if ($kind === 'type' && $type === '') {
			return null;
		}
		if ($kind === 'url' && $url === '' && $pattern === '') {
			return null;
		}
		if ($kind === 'pattern' && $pattern === '') {
			return null;
		}
		// هرگز خود پلاگین را بلاک نکن
		if (strpos($action, 'speedpulse_') === 0) {
			return null;
		}
		if (strpos($pattern, 'speedpulse') !== false || strpos($url, 'speedpulse') !== false) {
			return null;
		}

		$id = sanitize_key((string) ($row['id'] ?? ''));
		if ($id === '') {
			$id = substr(md5($kind . '|' . $url . '|' . $pattern . '|' . $action . '|' . $type), 0, 16);
		}
		if ($label === '') {
			if ($kind === 'action') {
				$label = 'اکشن: ' . $action;
			} elseif ($kind === 'type') {
				$label = 'دسته: ' . $type;
			} else {
				$label = $pattern !== '' ? $pattern : $url;
			}
		}

		return [
			'id'      => $id,
			'kind'    => $kind,
			'url'     => $url,
			'pattern' => $pattern,
			'action'  => $action,
			'type'    => $type,
			'label'   => function_exists('mb_substr') ? mb_substr($label, 0, 160) : substr($label, 0, 160),
			'scope'   => $scope,
			'at'      => (int) ($row['at'] ?? time()),
		];
	}

	/**
	 * @param false|array|\WP_Error $pre
	 * @param array<string, mixed>  $args
	 * @param string                $url
	 * @return false|array|\WP_Error
	 */
	public function filterHttp($pre, $args, $url)
	{
		if ($pre !== false) {
			return $pre;
		}
		if (! is_user_logged_in() || ! current_user_can('manage_options')) {
			return $pre;
		}
		$list = self::getList();
		if (! $list) {
			return $pre;
		}
		$urlStr = (string) $url;
		foreach ($list as $item) {
			$scope = (string) ($item['scope'] ?? 'both');
			if ($scope === 'client') {
				continue;
			}
			if ($this->serverMatch($item, $urlStr)) {
				return [
					'headers'  => [],
					'body'     => '',
					'response' => ['code' => 418, 'message' => 'Blocked by SpeedPulse'],
					'cookies'  => [],
					'filename' => null,
					'http_response' => null,
				];
			}
		}
		return $pre;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function serverMatch(array $item, string $url): bool
	{
		$kind = (string) ($item['kind'] ?? '');
		if ($kind === 'type') {
			// سرور فقط URL می‌بیند؛ typeهای ajax/rest را از URL حدس بزن
			$type = (string) ($item['type'] ?? '');
			if ($type === 'rest' && (strpos($url, '/wp-json/') !== false || strpos($url, 'rest_route=') !== false)) {
				return true;
			}
			return false;
		}
		if ($kind === 'action') {
			$action = (string) ($item['action'] ?? '');
			if ($action === '') {
				return false;
			}
			return (bool) preg_match('/(?:^|[?&])action=' . preg_quote($action, '/') . '(?:&|$)/i', $url);
		}
		$pattern = (string) ($item['pattern'] ?? '');
		if ($pattern !== '' && stripos($url, $pattern) !== false) {
			return true;
		}
		$itemUrl = (string) ($item['url'] ?? '');
		if ($itemUrl !== '' && stripos($url, $itemUrl) !== false) {
			return true;
		}
		return false;
	}
}
