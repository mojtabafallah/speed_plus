/**
 * جمع‌آوری زمان واقعی صفحه از دید مرورگر (مثل تب Network)
 * Heartbeat از Admin AJAX و REST جدا محاسبه می‌شود.
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

  /** برچسب‌های اکشن admin-ajax که با XHR/fetch ثبت می‌شوند */
  var ajaxTags = [];

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

  function shortName(url, action) {
    try {
      var u = new URL(url, window.location.href);
      var path = u.pathname + (u.search ? u.search.slice(0, 60) : '');
      if (action) {
        path += (path.indexOf('?') >= 0 ? '&' : '?') + 'action=' + action;
      }
      return path.length > 95 ? path.slice(0, 95) + '…' : path;
    } catch (e) {
      return String(url || '').slice(0, 95);
    }
  }

  function detectAction(body) {
    if (!body) return '';
    try {
      if (typeof body === 'string') {
        var m = body.match(/(?:^|&)action=([^&]*)/);
        if (m) return decodeURIComponent(m[1] || '');
        return '';
      }
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        var a = body.get('action');
        return a ? String(a) : '';
      }
      if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
        return body.get('action') || '';
      }
    } catch (e) {}
    return '';
  }

  function actionFromUrl(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.searchParams.get('action') || '';
    } catch (e) {
      var m = String(url).match(/[?&]action=([^&]+)/);
      return m ? decodeURIComponent(m[1]) : '';
    }
  }

  function isHeartbeatAction(action) {
    var a = String(action || '').toLowerCase();
    return a === 'heartbeat' || a === 'asheartbeat' || a === 'wp_heartbeat';
  }

  function isSpeedPulseAction(action) {
    return String(action || '').indexOf('speedpulse_') === 0;
  }

  function normalizeAjaxUrl(url) {
    try {
      var u = new URL(url, window.location.href);
      return u.origin + u.pathname;
    } catch (e) {
      return String(url || '').split('?')[0];
    }
  }

  function pushTag(url, startPerf, action, durationHint) {
    ajaxTags.push({
      urlKey: normalizeAjaxUrl(url),
      start: startPerf || 0,
      action: action || '',
      heartbeat: isHeartbeatAction(action),
      speedpulse: isSpeedPulseAction(action),
      durationHint: durationHint || 0,
      at: Date.now()
    });
    // جلوگیری از رشد بی‌نهایت
    if (ajaxTags.length > 200) {
      ajaxTags = ajaxTags.slice(-120);
    }
  }

  function findTag(url, startTime) {
    var key = normalizeAjaxUrl(url);
    var best = null;
    var bestDiff = 99999;
    for (var i = 0; i < ajaxTags.length; i++) {
      var t = ajaxTags[i];
      if (t.urlKey !== key) continue;
      var diff = Math.abs((t.start || 0) - (startTime || 0));
      if (diff < bestDiff && diff < 2500) {
        bestDiff = diff;
        best = t;
      }
    }
    return best;
  }

  function classify(url, initiator, actionHint) {
    var u = String(url || '').toLowerCase();
    var init = String(initiator || '').toLowerCase();
    var action = actionHint || actionFromUrl(url);

    if (isHeartbeatAction(action) || u.indexOf('action=heartbeat') !== -1) {
      return 'heartbeat';
    }
    if (isSpeedPulseAction(action)) {
      return 'speedpulse';
    }
    if (u.indexOf('/wp-json/') !== -1 || u.indexOf('rest_route=') !== -1) {
      return 'rest';
    }
    if (u.indexOf('admin-ajax.php') !== -1) {
      return 'ajax';
    }
    if (u.indexOf('load-scripts.php') !== -1 || u.indexOf('load-styles.php') !== -1) {
      return 'bundle';
    }
    if (init === 'xmlhttprequest' || init === 'fetch') {
      return 'xhr';
    }
    if (init === 'script' || /\.js(\?|$)/.test(u)) return 'script';
    if (init === 'css' || /\.css(\?|$)/.test(u)) return 'style';
    if (init === 'img' || init === 'image') return 'image';
    return 'other';
  }

  function typeLabel(k) {
    return ({
      rest: 'REST API (wp-json)',
      ajax: 'Admin AJAX (غیر Heartbeat)',
      heartbeat: 'Heartbeat وردپرس',
      speedpulse: 'درخواست‌های اسپید‌پالس',
      bundle: 'load-scripts / load-styles',
      xhr: 'XHR / Fetch سایر',
      script: 'اسکریپت',
      style: 'استایل',
      image: 'تصویر',
      other: 'سایر منابع'
    })[k] || k;
  }

  function installAjaxHooks() {
    // XMLHttpRequest
    if (window.XMLHttpRequest) {
      var open = XMLHttpRequest.prototype.open;
      var send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function (method, url) {
        this._spUrl = url;
        this._spStart = (window.performance && performance.now) ? performance.now() : Date.now();
        return open.apply(this, arguments);
      };
      XMLHttpRequest.prototype.send = function (body) {
        var self = this;
        var action = detectAction(body) || actionFromUrl(self._spUrl || '');
        self._spAction = action;
        var start = self._spStart || 0;
        pushTag(self._spUrl || '', start, action, 0);
        self.addEventListener('loadend', function () {
          var dur = ((window.performance && performance.now) ? performance.now() : Date.now()) - start;
          pushTag(self._spUrl || '', start, action, dur);
        });
        return send.apply(this, arguments);
      };
    }

    // fetch
    if (typeof window.fetch === 'function') {
      var origFetch = window.fetch;
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        var start = (window.performance && performance.now) ? performance.now() : Date.now();
        var body = init && init.body ? init.body : null;
        var action = detectAction(body) || actionFromUrl(url);
        pushTag(url, start, action, 0);
        return origFetch.apply(this, arguments).then(function (res) {
          var dur = ((window.performance && performance.now) ? performance.now() : Date.now()) - start;
          pushTag(url, start, action, dur);
          return res;
        });
      };
    }
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
    var heartbeatCount = 0;
    var ajaxCount = 0;
    var restCount = 0;

    resources.forEach(function (r) {
      var dur = r.duration || 0;
      var end = (r.startTime || 0) + dur;
      if (end > maxEnd) maxEnd = end;

      var tag = findTag(r.name, r.startTime || 0);
      var action = tag ? tag.action : actionFromUrl(r.name);
      var type = classify(r.name, r.initiatorType, action);

      // درخواست‌های خود پلاگین را از آمار کندی اصلی کنار بگذار (ولی جدا نگه دار)
      if (type === 'speedpulse') {
        // اختیاری: می‌توان نادیده گرفت؛ فعلاً جدا نمایش می‌دهیم
      }

      if (!byType[type]) {
        byType[type] = { count: 0, total_ms: 0, max_ms: 0 };
      }
      byType[type].count += 1;
      byType[type].total_ms += dur;
      byType[type].max_ms = Math.max(byType[type].max_ms, dur);

      if (type === 'heartbeat') heartbeatCount++;
      if (type === 'ajax') ajaxCount++;
      if (type === 'rest') restCount++;

      var labelName = shortName(r.name, action);
      if (type === 'heartbeat') {
        labelName = 'Heartbeat → ' + labelName;
      } else if (type === 'ajax' && action) {
        labelName = 'AJAX [' + action + '] → ' + shortName(r.name, '');
      }

      slow.push({
        name: labelName,
        url: r.name,
        type: type,
        action: action || '',
        initiator: r.initiatorType || '',
        duration_ms: Math.round(dur * 100) / 100,
        start_ms: Math.round((r.startTime || 0) * 100) / 100,
        waiting_ms: Math.round(Math.max(0, (r.responseStart || 0) - (r.requestStart || 0)) * 100) / 100,
        transfer_kb: r.transferSize ? Math.round((r.transferSize / 1024) * 10) / 10 : 0
      });
    });

    slow.sort(function (a, b) { return b.duration_ms - a.duration_ms; });
    var top = slow.slice(0, 30);

    wallMs = Math.max(wallMs, maxEnd);

    var typeItems = Object.keys(byType).map(function (k) {
      var row = byType[k];
      return {
        key: k,
        label: typeLabel(k),
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
      heartbeat_count: heartbeatCount,
      ajax_count: ajaxCount,
      rest_count: restCount,
      transfer_kb: Math.round((transferSize / 1024) * 10) / 10,
      by_type: typeItems,
      slowest: top.map(function (s) {
        s.human = fmt(s.duration_ms);
        return s;
      }),
      note_fa: 'Heartbeat از Admin AJAX و REST جدا شده است. زمان سرور فقط HTML است؛ زمان مرورگر شامل Network می‌شود.'
    };
  }

  function send(reason) {
    var payload = collect();
    if (!payload) return;

    var hash = String(payload.browser_wall_ms) + ':' + payload.resource_count + ':' +
      payload.heartbeat_count + ':' + payload.ajax_count + ':' +
      (payload.slowest[0] && payload.slowest[0].duration_ms);
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

    // از sendBeacon برای گزارش خودمان استفاده می‌کنیم؛ در دسته‌بندی speedpulse می‌افتد
    if (navigator.sendBeacon) {
      navigator.sendBeacon(D.ajaxUrl, fd);
    } else {
      // fetch اصلی را صدا نزن تا حلقه قلاب خودمان را شلوغ نکند؛ از XHR خام
      var xhr = new window.XMLHttpRequest();
      xhr.open('POST', D.ajaxUrl);
      xhr.send(fd);
    }

    D.browserMetrics = payload;
    try {
      window.dispatchEvent(new CustomEvent('speedpulse:browser-metrics', { detail: payload }));
    } catch (e) {}
  }

  function startObserver() {
    if (!window.PerformanceObserver) return;
    try {
      observer = new PerformanceObserver(function () {
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
    installAjaxHooks();
    startObserver();

    if (document.readyState === 'complete') {
      setTimeout(function () { send('load'); }, 800);
    } else {
      window.addEventListener('load', function () {
        setTimeout(function () { send('load'); }, 800);
      });
    }

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
