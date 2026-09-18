<?php
/**
 * هسته اتصال عمومی به ارائه‌دهندگان هوش مصنوعی (OpenAI-Compatible).
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\AI;

final class AiClient
{
	public function boot(): void
	{
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{ok:bool,message:string,content:string}
	 */
	public function analyze(array $context): array
	{
		if (! current_user_can('manage_options')) {
			return ['ok' => false, 'message' => 'دسترسی کافی ندارید.', 'content' => ''];
		}

		$settings = get_option('speedpulse_settings', []);
		$apiKey   = (string) ($settings['ai_api_key'] ?? '');
		$model    = (string) ($settings['ai_model'] ?? 'gpt-4o');
		$baseUrl  = rtrim((string) ($settings['ai_base_url'] ?? ''), '/');
		$provider = (string) ($settings['ai_provider'] ?? 'openai');

		if ($apiKey === '' && $baseUrl === '') {
			return [
				'ok'      => false,
				'message' => 'کلید API یا آدرس پایه مدل محلی را در تنظیمات وارد کنید.',
				'content' => '',
			];
		}

		if ($baseUrl === '') {
			$baseUrl = match ($provider) {
				'anthropic' => 'https://api.anthropic.com/v1',
				'deepseek'  => 'https://api.deepseek.com/v1',
				'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/openai',
				default     => 'https://api.openai.com/v1',
			};
		}

		$prompt = $this->buildPrompt($context);
		$endpoint = $baseUrl . '/chat/completions';

		$body = [
			'model'       => $model,
			'temperature' => 0.2,
			'messages'    => [
				[
					'role'    => 'system',
					'content' => 'تو مهندس ارشد بهینه‌سازی وردپرس و ووکامرس هستی. فقط به فارسی روان و تخصصی پاسخ بده. خروجی باید عملی، اولویت‌بندی‌شده و قابل اجرا باشد.',
				],
				[
					'role'    => 'user',
					'content' => $prompt,
				],
			],
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 90,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $apiKey,
				],
				'body'    => wp_json_encode($body),
			]
		);

		if (is_wp_error($response)) {
			return ['ok' => false, 'message' => $response->get_error_message(), 'content' => ''];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$data = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($code >= 400 || ! is_array($data)) {
			return [
				'ok'      => false,
				'message' => 'پاسخ نامعتبر از سرویس هوش مصنوعی (کد ' . $code . ').',
				'content' => is_string(wp_remote_retrieve_body($response)) ? substr((string) wp_remote_retrieve_body($response), 0, 500) : '',
			];
		}

		$content = (string) ($data['choices'][0]['message']['content'] ?? '');
		if ($content === '') {
			return ['ok' => false, 'message' => 'محتوای تحلیلی خالی بود.', 'content' => ''];
		}

		set_transient('speedpulse_ai_last_analysis', $content, DAY_IN_SECONDS);

		return ['ok' => true, 'message' => 'تحلیل دریافت شد.', 'content' => $content];
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function buildPrompt(array $context): string
	{
		$json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		return <<<PROMPT
داده‌های پروفایلینگ سایت وردپرس زیر را کالبدشکافی کن و یک سناریوی جامع بهینه‌سازی به فارسی ارائه بده:
1) گلوگاه‌های اصلی (TTFB، کوئری، هوک، شبکه، ووکامرس)
2) اولویت‌بندی اقدامات با اثر تقریبی
3) پیشنهادهای کد/پیکربندی ایمن
4) هشدارهای ریسک

داده:
{$json}
PROMPT;
	}
}
