<?php
/**
 * صفحه تنظیمات پیشخوان.
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Admin;

final class SettingsPage
{
	public function boot(): void
	{
		add_action('admin_menu', [$this, 'menu']);
	}

	public function menu(): void
	{
		add_menu_page(
			'اسپید‌پالس پرو',
			'اسپید‌پالس پرو',
			'manage_options',
			'speedpulse-pro',
			[$this, 'render'],
			'dashicons-performance',
			58
		);
	}

	public function render(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$s = get_option('speedpulse_settings', []);
		?>
		<div class="wrap speedpulse-settings-wrap" dir="rtl">
			<h1>اسپید‌پالس پرو اولترا</h1>
			<p>تنظیمات هسته مانیتورینگ، هوش مصنوعی و آستانه‌های هشدار. برای پنل زنده از نوار بالای وردپرس استفاده کنید.</p>
			<p class="description">
				نویسنده: <a href="<?php echo esc_url(SPEEDPULSE_AUTHOR_URI); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(SPEEDPULSE_AUTHOR); ?></a>
				—
				<a href="<?php echo esc_url(SPEEDPULSE_GITHUB); ?>" target="_blank" rel="noopener noreferrer">مخزن GitHub</a>
			</p>

			<form id="speedpulse-settings-form" class="speedpulse-card">
				<table class="form-table" role="presentation">
					<tr>
						<th>آستانه کوئری کند (میلی‌ثانیه)</th>
						<td><input type="number" step="0.1" name="slow_query_ms" value="<?php echo esc_attr((string) ($s['slow_query_ms'] ?? 20)); ?>" /></td>
					</tr>
					<tr>
						<th>حد هشدار تعداد المان DOM</th>
						<td><input type="number" name="dom_warn_elements" value="<?php echo esc_attr((string) ($s['dom_warn_elements'] ?? 800)); ?>" /></td>
					</tr>
					<tr>
						<th>ارائه‌دهنده هوش مصنوعی</th>
						<td>
							<select name="ai_provider">
								<?php
								$providers = [
									'openai'    => 'OpenAI',
									'anthropic' => 'Claude / Anthropic',
									'deepseek'  => 'DeepSeek',
									'gemini'    => 'Gemini',
									'custom'    => 'سفارشی / محلی (Ollama)',
								];
								$current = (string) ($s['ai_provider'] ?? 'openai');
								foreach ($providers as $val => $label) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr($val),
										selected($current, $val, false),
										esc_html($label)
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th>مدل</th>
						<td><input type="text" class="regular-text" name="ai_model" value="<?php echo esc_attr((string) ($s['ai_model'] ?? 'gpt-4o')); ?>" placeholder="gpt-4o / claude-3-5-sonnet / deepseek-chat" /></td>
					</tr>
					<tr>
						<th>کلید API</th>
						<td><input type="password" class="regular-text" name="ai_api_key" value="" placeholder="برای حفظ کلید فعلی خالی بگذارید" autocomplete="off" /></td>
					</tr>
					<tr>
						<th>آدرس پایه سفارشی</th>
						<td><input type="url" class="regular-text" name="ai_base_url" value="<?php echo esc_attr((string) ($s['ai_base_url'] ?? '')); ?>" placeholder="http://127.0.0.1:11434/v1" /></td>
					</tr>
					<tr>
						<th>ضبط‌ها</th>
						<td>
							<label><input type="checkbox" name="capture_queries" value="1" <?php checked(! empty($s['capture_queries'])); ?> /> کوئری‌ها</label><br />
							<label><input type="checkbox" name="capture_hooks" value="1" <?php checked(! empty($s['capture_hooks'])); ?> /> هوک‌ها</label><br />
							<label><input type="checkbox" name="capture_network" value="1" <?php checked(! empty($s['capture_network'])); ?> /> شبکه</label>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary">ذخیره تنظیمات</button>
					<button type="button" class="button" id="speedpulse-open-drawer">باز کردن پنل زنده</button>
				</p>
				<p class="description" id="speedpulse-settings-msg"></p>
			</form>
		</div>
		<?php
	}
}
