<?php
/**
 * بازرس DOM و منابع مسدودکننده رندر (اسکریپت سمت کلاینت + متادیتا).
 *
 * @package SpeedPulsePro
 */

declare(strict_types=1);

namespace SpeedPulsePro\Frontend;

final class DomInspector
{
	public function boot(): void
	{
		add_action('wp_footer', [$this, 'injectProbe'], 99);
		add_action('admin_footer', [$this, 'injectProbe'], 99);
	}

	public function injectProbe(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		if (! \SpeedPulsePro\Core\Profiler::quickEnabled()) {
			return;
		}

		$settings = get_option('speedpulse_settings', []);
		$limit    = (int) ($settings['dom_warn_elements'] ?? 800);
		$nonce    = wp_create_nonce('speedpulse_ajax');
		?>
		<script id="speedpulse-dom-probe">
		(function () {
			try {
				var nodes = document.getElementsByTagName('*').length;
				var depth = 0;
				(function walk(el, d) {
					depth = Math.max(depth, d);
					for (var i = 0; i < el.children.length; i++) walk(el.children[i], d + 1);
				})(document.documentElement, 1);

				var blocking = [];
				document.querySelectorAll('link[rel="stylesheet"], script[src]').forEach(function (el) {
					if (el.tagName === 'SCRIPT' && el.hasAttribute('async')) return;
					if (el.tagName === 'SCRIPT' && el.hasAttribute('defer')) return;
					blocking.push({
						tag: el.tagName.toLowerCase(),
						href: el.href || el.src || '',
						render_blocking: true
					});
				});

				var payload = {
					action: 'speedpulse_dom_report',
					_ajax_nonce: <?php echo wp_json_encode($nonce); ?>,
					elements: nodes,
					depth: depth,
					warn: nodes > <?php echo (int) $limit; ?>,
					blocking: JSON.stringify(blocking.slice(0, 40)),
					fonts: document.fonts ? document.fonts.size : 0
				};
				if (window.navigator && navigator.sendBeacon) {
					var fd = new FormData();
					Object.keys(payload).forEach(function (k) { fd.append(k, payload[k]); });
					navigator.sendBeacon(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, fd);
				}
			} catch (e) {}
		})();
		</script>
		<?php
	}
}
