<?php
/**
 * حسابرس WP-Cron و Action Scheduler.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Cron;

use SpeedPulsePro\Core\Profiler;

final class CronAuditor
{
	/** @var Profiler */
	private $profiler;

	public function __construct(Profiler $profiler)
	{
		$this->profiler = $profiler;
	}

	public function boot(): void
	{
		add_action('action_scheduler_begin_execute', [$this, 'onActionStart'], 10, 2);
		add_filter('schedule_event', [$this, 'noteSchedule']);
		add_action('shutdown', [$this, 'reportDueCrons'], 5);
	}

	/**
	 * @param mixed $context
	 */
	public function onActionStart(int $actionId, $context = null): void
	{
		$this->profiler->addCron([
			'type'      => 'action_scheduler',
			'action_id' => $actionId,
			'time_ms'   => round($this->profiler->elapsedMs(), 2),
			'note'      => 'اجرای اکشن زمان‌بندی‌شده در حین درخواست',
		]);
		$this->profiler->mark('Action Scheduler #' . $actionId, 'زمان‌بندی');
	}

	/**
	 * @param object|false $event
	 * @return object|false
	 */
	public function noteSchedule($event)
	{
		if (is_object($event) && isset($event->hook)) {
			$this->profiler->addCron([
				'type'    => 'schedule',
				'hook'    => (string) $event->hook,
				'time_ms' => round($this->profiler->elapsedMs(), 2),
			]);
		}
		return $event;
	}

	public function reportDueCrons(): void
	{
		$crons = _get_cron_array();
		if (! is_array($crons)) {
			return;
		}
		$now  = time();
		$due  = 0;
		$list = [];
		foreach ($crons as $timestamp => $hooks) {
			if ((int) $timestamp > $now) {
				continue;
			}
			if (! is_array($hooks)) {
				continue;
			}
			foreach ($hooks as $hook => $events) {
				$due++;
				$list[] = [
					'hook' => (string) $hook,
					'due'  => (int) $timestamp,
					'late' => $now - (int) $timestamp,
				];
			}
		}

		if ($due > 0) {
			$this->profiler->addCron([
				'type'    => 'due_summary',
				'count'   => $due,
				'items'   => array_slice($list, 0, 20),
				'note'    => 'تعداد کارهای سررسیدشده که ممکن است حین لود صفحه اجرا شوند',
				'time_ms' => round($this->profiler->elapsedMs(), 2),
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function inventory(): array
	{
		$crons = _get_cron_array();
		$count = 0;
		$hooks = [];
		if (is_array($crons)) {
			foreach ($crons as $hooksMap) {
				if (! is_array($hooksMap)) {
					continue;
				}
				foreach ($hooksMap as $hook => $events) {
					$count += is_array($events) ? count($events) : 0;
					$hooks[$hook] = ($hooks[$hook] ?? 0) + (is_array($events) ? count($events) : 0);
				}
			}
		}
		arsort($hooks);

		$asPending = 0;
		if (function_exists('as_get_scheduled_actions')) {
			$asPending = count(as_get_scheduled_actions(['status' => 'pending', 'per_page' => 50]));
		}

		return [
			'cron_events'       => $count,
			'top_hooks'         => array_slice($hooks, 0, 15, true),
			'action_scheduler'  => $asPending,
		];
	}
}
