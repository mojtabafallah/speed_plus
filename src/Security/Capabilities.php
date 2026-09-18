<?php
/**
 * قابلیت‌های امنیتی مشترک.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Security;

final class Capabilities
{
	public static function canManage(): bool
	{
		return current_user_can('manage_options');
	}
}
