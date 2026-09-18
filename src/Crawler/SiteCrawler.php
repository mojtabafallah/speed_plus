<?php
/**
 * خزش‌گر سایت با قابلیت مکث و ادامه.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Crawler;

final class SiteCrawler
{
	private const OPTION = 'speedpulse_crawler_state';
	private const BATCH  = 5;

	public function boot(): void
	{
		add_action('speedpulse_crawler_tick', [$this, 'tick']);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array
	{
		$state = get_option(self::OPTION, []);
		return is_array($state) ? $state : [];
	}

	/**
	 * @return array{ok:bool,message:string,state:array<string,mixed>}
	 */
	public function start(): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.', 'state' => $this->state()];
		}

		$urls = $this->collectUrls();
		$state = [
			'status'  => 'running',
			'offset'  => 0,
			'total'   => count($urls),
			'queue'   => $urls,
			'results' => [],
			'updated' => time(),
		];
		update_option(self::OPTION, $state, false);
		$this->schedule();

		return ['ok' => true, 'message' => 'خزش آغاز شد.', 'state' => $state];
	}

	/**
	 * @return array{ok:bool,message:string,state:array<string,mixed>}
	 */
	public function pause(): array
	{
		$state           = $this->state();
		$state['status'] = 'paused';
		$state['updated'] = time();
		update_option(self::OPTION, $state, false);
		wp_clear_scheduled_hook('speedpulse_crawler_tick');
		return ['ok' => true, 'message' => 'خزش متوقف شد.', 'state' => $state];
	}

	/**
	 * @return array{ok:bool,message:string,state:array<string,mixed>}
	 */
	public function resume(): array
	{
		$state            = $this->state();
		$state['status']  = 'running';
		$state['updated'] = time();
		update_option(self::OPTION, $state, false);
		$this->schedule();
		return ['ok' => true, 'message' => 'خزش ادامه یافت.', 'state' => $state];
	}

	public function tick(): void
	{
		$state = $this->state();
		if (($state['status'] ?? '') !== 'running') {
			return;
		}

		$queue  = $state['queue'] ?? [];
		$offset = (int) ($state['offset'] ?? 0);
		$total  = (int) ($state['total'] ?? count($queue));
		$slice  = array_slice($queue, $offset, self::BATCH);

		foreach ($slice as $url) {
			$t0  = microtime(true);
			$res = wp_remote_get(
				$url,
				[
					'timeout'     => 20,
					'redirection' => 3,
					'headers'     => ['X-SpeedPulse-Crawler' => '1'],
				]
			);
			$ms = (microtime(true) - $t0) * 1000;
			$row = [
				'url'     => $url,
				'ttfb_ms' => round($ms, 2),
				'code'    => is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res),
				'bytes'   => is_wp_error($res) ? 0 : strlen((string) wp_remote_retrieve_body($res)),
				'error'   => is_wp_error($res) ? $res->get_error_message() : '',
			];
			$state['results'][] = $row;
		}

		$state['offset']  = $offset + count($slice);
		$state['updated'] = time();

		if ($state['offset'] >= $total) {
			$state['status'] = 'done';
			usort(
				$state['results'],
				static fn (array $a, array $b): int => ($b['ttfb_ms'] <=> $a['ttfb_ms'])
			);
			update_option(self::OPTION, $state, false);
			return;
		}

		update_option(self::OPTION, $state, false);
		$this->schedule();
	}

	private function schedule(): void
	{
		if (! wp_next_scheduled('speedpulse_crawler_tick')) {
			wp_schedule_single_event(time() + 2, 'speedpulse_crawler_tick');
		}
		spawn_cron();
	}

	/**
	 * @return list<string>
	 */
	private function collectUrls(): array
	{
		$urls = [home_url('/')];

		$posts = get_posts([
			'post_type'      => ['post', 'page', 'product'],
			'post_status'    => 'publish',
			'posts_per_page' => 80,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);
		foreach ($posts as $id) {
			$link = get_permalink((int) $id);
			if (is_string($link) && $link !== '') {
				$urls[] = $link;
			}
		}

		$terms = get_terms([
			'taxonomy'   => ['category', 'product_cat'],
			'hide_empty' => true,
			'number'     => 40,
		]);
		if (! is_wp_error($terms) && is_array($terms)) {
			foreach ($terms as $term) {
				$link = get_term_link($term);
				if (is_string($link)) {
					$urls[] = $link;
				}
			}
		}

		return array_values(array_unique($urls));
	}
}
