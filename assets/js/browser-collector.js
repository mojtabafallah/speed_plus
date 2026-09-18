/**
 * جمع‌آوری زمان واقعی صفحه از دید مرورگر (مثل تب Network)
 * شامل Navigation Timing + Resource Timing + درخواست‌های دیرهنگام AJAX/REST
 */
(function () {
  'use strict';

  var D = window.SpeedPulseData || {};
  if (!D.collectBrowser || !D.ajaxUrl) {
    return;
  }

  var sentOnce = false;
  var lastPayloadHash = '';
  var observer = null;
  var pageStart = (window.performance && performance.timing && performance.timing.navigationStart)
    ? performance.timing.navigationStart
    : Date.now();

  function fmt(ms) {
    var n = Math.max(0, Number(ms) || 0);
    if (n >= 60000) {
      var min = Math.floor(n / 60000);
      var rem = (n % 60000) / 1000;
      return min + ' دقیقه و ' + rem.toFixed(2) + ' ثانیه';
    }
    if (n >= 1000) {
      return (n / 1000).toFixed(3) + ' ثانیه';
    }
    return n.toFixed(2) + ' میلی‌ثانیه';
  }

  function shortName(url) {
    try {
      var u = new URL(url, window.location.href);
      var path = u.pathname + (u.search ? u.search.slice(0, 80) : '');
      return path.length > 90 ? path.slice(0, 90) + '…' : path;
    } catch (e) {
      return String(url || '').slice(0, 90);
    }
  }

  function classify(url, initiator) {
    var u = String(url || '').toLowerCase();
    var init = String(initiator || '').toLowerCase();
    if (u.indexOf('/wp-json/') !== -1 || u.indexOf('rest_route=') !== -1) return 'rest';
    if (u.indexOf('admin-ajax.php') !== -1) return 'ajax';
    if (u.indexOf('load-scripts.php') !== -1 || u.indexOf('load-styles.php') !== -1) return 'bundle';
    if (init === 'xmlhttprequest' || init === 'fetch') return 'xhr';
    if (init === 'script' || /\.js(\?|$)/.test(u)) return 'script';
    if (init === 'css' || /\.css(\?|$)/.test(u)) return 'style';
    if (init === 'img' || init === 'image') return 'image';
    return 'other';
  }

  function collect() {
    if (!window.performance || !performance.getEntriesByType) {
      return null;
    }

    var navEntries = performance.getEntriesByType('navigation');
    var nav = navEntries && navEntries[0] ? navEntries[0] : null;
    var resources = performance.getEntriesByType('resource') || [];

    var ttfb = 0;
    var domContent = 0;
    var loadEvent = 0;
    var transferSize = 0;

    if (nav) {
      ttfb = nav.responseStart || 0;
      domContent = nav.domContentLoadedEventEnd || 0;
      loadEvent = nav.loadEventEnd || nav.duration || 0;
      transferSize = nav.transferSize || 0;
    } else if (performance.timing) {
      var t = performance.timing;
      ttfb = Math.max(0, t.responseStart - t.navigationStart);
      domContent = Math.max(0, t.domContentLoadedEventEnd - t.navigationStart);
      loadEvent = Math.max(0, t.loadEventEnd - t.navigationStart);
    }

    var wallMs = Math.max(loadEvent, Date.now() - pageStart);
    var slow = [];
    var byType = {};
    var maxEnd = loadEvent;

    resources.forEach(function (r) {
      var dur = r.duration || 0;
      var end = (r.startTime || 0) + dur;
      if (end > maxEnd) maxEnd = end;

      var type = classify(r.name, r.initiatorType);
      if (!byType[type]) {
        byType[type] = { count: 0, total_ms: 0, max_ms: 0 };
      }
      byType[type].count += 1;
      byType[type].total_ms += dur;
      byType[type].max_ms = Math.max(byType[type].max_ms, dur);

      slow.push({
        name: shortName(r.name),
        url: r.name,
        type: type,
        initiator: r.initiatorType || '',
        duration_ms: Math.round(dur * 100) / 100,
        start_ms: Math.round((r.startTime || 0) * 100) / 100,
        waiting_ms: Math.round(Math.max(0, (r.responseStart || 0) - (r.requestStart || 0)) * 100) / 100,
        transfer_kb: r.transferSize ? Math.round((r.transferSize / 1024) * 10) / 10 : 0
      });
    });

    slow.sort(function (a, b) { return b.duration_ms - a.duration_ms; });
    var top = slow.slice(0, 25);

    // زمان واقعی تجربه کاربر ≈ آخرین منبع تمام‌شده یا load
    wallMs = Math.max(wallMs, maxEnd);

    var typeItems = Object.keys(byType).map(function (k) {
      var row = byType[k];
      return {
        key: k,
        label: ({
          rest: 'REST API (wp-json)',
          ajax: 'Admin AJAX',
          bundle: 'load-scripts / load-styles',
          xhr: 'XHR / Fetch',
          script: 'اسکریپت',
          style: 'استایل',
          image: 'تصویر',
          other: 'سایر منابع'
        })[k] || k,
        count: row.count,
        total_ms: Math.round(row.total_ms * 100) / 100,
        max_ms: Math.round(row.max_ms * 100) / 100,
        human_max: fmt(row.max_ms),
        human_sum: fmt(row.total_ms)
      };
    }).sort(function (a, b) { return b.max_ms - a.max_ms; });

    return {
      page_url: window.location.href,
      page_path: window.location.pathname + window.location.search,
      collected_at: Date.now(),
      server_document: {
        ttfb_ms: Math.round(ttfb * 100) / 100,
        dom_content_ms: Math.round(domContent * 100) / 100,
        load_event_ms: Math.round(loadEvent * 100) / 100,
        human_ttfb: fmt(ttfb),
        human_dom: fmt(domContent),
        human_load: fmt(loadEvent)
      },
      browser_wall_ms: Math.round(wallMs * 100) / 100,
      browser_wall_human: fmt(wallMs),
      resource_count: resources.length,
      transfer_kb: Math.round((transferSize / 1024) * 10) / 10,
      by_type: typeItems,
      slowest: top.map(function (s) {
        s.human = fmt(s.duration_ms);
        return s;
      }),
      note_fa: 'زمان سرور فقط تولید HTML همان درخواست است. زمان مرورگر شامل همه درخواست‌های بعدی Network (مثل wp-json ووکامرس) هم می‌شود.'
    };
  }

  function send(reason) {
    var payload = collect();
    if (!payload) return;

    var hash = String(payload.browser_wall_ms) + ':' + payload.resource_count + ':' + (payload.slowest[0] && payload.slowest[0].duration_ms);
    if (hash === lastPayloadHash && sentOnce && reason !== 'final') {
      return;
    }
    lastPayloadHash = hash;
    sentOnce = true;

    var fd = new FormData();
    fd.append('action', 'speedpulse_browser_report');
    fd.append('_ajax_nonce', D.nonce || '');
    fd.append('payload', JSON.stringify(payload));
    fd.append('reason', reason || 'update');

    if (navigator.sendBeacon) {
      navigator.sendBeacon(D.ajaxUrl, fd);
    } else {
      fetch(D.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd }).catch(function () {});
    }

    // در دسترس پنل فعلی
    D.browserMetrics = payload;
    try {
      window.dispatchEvent(new CustomEvent('speedpulse:browser-metrics', { detail: payload }));
    } catch (e) {}
  }

  function startObserver() {
    if (!window.PerformanceObserver) return;
    try {
      observer = new PerformanceObserver(function () {
        // debounce سبک
        clearTimeout(startObserver._t);
        startObserver._t = setTimeout(function () { send('observer'); }, 1200);
      });
      observer.observe({ type: 'resource', buffered: true });
    } catch (e) {
      try {
        observer = new PerformanceObserver(function () {
          clearTimeout(startObserver._t);
          startObserver._t = setTimeout(function () { send('observer'); }, 1200);
        });
        observer.observe({ entryTypes: ['resource'] });
      } catch (e2) {}
    }
  }

  function boot() {
    startObserver();

    if (document.readyState === 'complete') {
      setTimeout(function () { send('load'); }, 800);
    } else {
      window.addEventListener('load', function () {
        setTimeout(function () { send('load'); }, 800);
      });
    }

    // ووکامرس ادمین بعد از لود هنوز REST می‌زند
    setTimeout(function () { send('tick-5s'); }, 5000);
    setTimeout(function () { send('tick-12s'); }, 12000);
    setTimeout(function () { send('tick-25s'); }, 25000);
    setTimeout(function () { send('final'); }, 35000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
