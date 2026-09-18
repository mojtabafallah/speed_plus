<?php
/**
 * ردیاب درخواست‌های شبکه مسدودکننده (cURL / wp_remote_*).
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Core;

final class NetworkTracker
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		add_filter('http_request_args', [$this, 'beforeRequest'], 10, 2);
		add_action('http_api_debug', [$this, 'afterRequest'], 10, 5);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function beforeRequest(array $args, string $url): array
	{
		$args['_speedpulse_start'] = microtime(true);
		return $args;
	}

	/**
	 * @param mixed                $response
	 * @param array<string, mixed> $args
	 */
	public function afterRequest($response, string $context, string $class, array $args, string $url): void
	{
		if ($context !== 'response') {
			return;
		}

		$start = (float) ($args['_speedpulse_start'] ?? microtime(true));
		$ms    = (microtime(true) - $start) * 1000;
		$code  = 0;
		$error = '';

		if (is_wp_error($response)) {
			$error = $response->get_error_message();
		} elseif (is_array($response)) {
			$code = (int) wp_remote_retrieve_response_code($response);
		}

		$blocking = ! isset($args['blocking']) || (bool) $args['blocking'];

		$this->profiler->addNetwork([
			'url'       => esc_url_raw($url),
			'time_ms'   => round($ms, 2),
			'blocking'  => $blocking,
			'code'      => $code,
			'error'     => $error,
			'method'    => (string) ($args['method'] ?? 'GET'),
			'class'     => $class,
		]);
	}
}
