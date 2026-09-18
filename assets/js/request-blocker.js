/**
 * بلاک آزمایشی درخواست‌های مرورگر (XHR / fetch / sendBeacon)
 * باید زود لود شود تا قبل از افزونه‌های دیگر قلاب بخورد.
 */
(function () {
  'use strict';

  var CFG = window.SpeedPulseBlock || {};
  var STORAGE_KEY = 'speedpulse_blocked_requests_v1';
  var items = [];

  function loadItems() {
    var fromServer = Array.isArray(CFG.items) ? CFG.items.slice() : [];
    var fromLocal = [];
    try {
      fromLocal = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
      if (!Array.isArray(fromLocal)) fromLocal = [];
    } catch (e) {
      fromLocal = [];
    }
    // ادغام بر اساس id — اولویت با سرور
    var map = {};
    fromLocal.forEach(function (it) {
      if (it && it.id) map[it.id] = it;
    });
    fromServer.forEach(function (it) {
      if (it && it.id) map[it.id] = it;
    });
    items = Object.keys(map).map(function (k) { return map[k]; });
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e2) {}
  }

  function saveLocal() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch (e) {}
    CFG.items = items;
    if (window.SpeedPulseData) {
      window.SpeedPulseData.blockedRequests = items;
    }
    try {
      window.dispatchEvent(new CustomEvent('speedpulse:blocks-changed', { detail: items }));
    } catch (e3) {}
  }

  function actionFromBody(body) {
    if (!body) return '';
    try {
      if (typeof body === 'string') {
        var m = body.match(/(?:^|&)action=([^&]*)/);
        return m ? decodeURIComponent(m[1] || '') : '';
      }
      if (typeof FormData !== 'undefined' && body instanceof FormData) {
        var a = body.get('action');
        return a ? String(a) : '';
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

  function isHeartbeat(action) {
    var a = String(action || '').toLowerCase();
    return a === 'heartbeat' || a === 'asheartbeat' || a === 'wp_heartbeat';
  }

  function classifyUrl(url, action) {
    var u = String(url || '').toLowerCase();
    var act = String(action || '');
    if (act.indexOf('speedpulse_') === 0 || u.indexOf('speedpulse') !== -1) return 'speedpulse';
    if (isHeartbeat(act)) return 'heartbeat';
    if (u.indexOf('/wp-json/') !== -1 || u.indexOf('rest_route=') !== -1) return 'rest';
    if (u.indexOf('admin-ajax.php') !== -1) return 'ajax';
    return 'other';
  }

  function shouldBlock(url, action, scopeWanted) {
    var act = action || actionFromUrl(url);
    var type = classifyUrl(url, act);
    if (type === 'speedpulse' || String(act).indexOf('speedpulse_') === 0) {
      return null;
    }
    var u = String(url || '');
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      var scope = it.scope || 'both';
      if (scopeWanted === 'client' && scope === 'server') continue;
      if (it.kind === 'action' && it.action && act === it.action) return it;
      if (it.kind === 'type' && it.type && type === it.type) return it;
      if (it.pattern && u.indexOf(it.pattern) !== -1) return it;
      if (it.url && u.indexOf(it.url) !== -1) return it;
    }
    return null;
  }

  function fakeResponse(url) {
    var body = JSON.stringify({
      success: false,
      data: { message: 'بلاک‌شده توسط اسپید‌پالس پرو', blocked: true }
    });
    if (typeof Response !== 'undefined') {
      return new Response(body, {
        status: 418,
        statusText: 'Blocked by SpeedPulse',
        headers: { 'Content-Type': 'application/json' }
      });
    }
    return null;
  }

  function install() {
    // XHR
    if (window.XMLHttpRequest) {
      var open = XMLHttpRequest.prototype.open;
      var send = XMLHttpRequest.prototype.send;
      XMLHttpRequest.prototype.open = function (method, url) {
        this._spBlockUrl = url;
        return open.apply(this, arguments);
      };
      XMLHttpRequest.prototype.send = function (body) {
        var url = this._spBlockUrl || '';
        var action = actionFromBody(body) || actionFromUrl(url);
        var hit = shouldBlock(url, action, 'client');
        if (hit) {
          this._spBlocked = true;
          try {
            Object.defineProperty(this, 'status', { configurable: true, get: function () { return 418; } });
            Object.defineProperty(this, 'readyState', { configurable: true, get: function () { return 4; } });
            Object.defineProperty(this, 'responseText', {
              configurable: true,
              get: function () {
                return JSON.stringify({ success: false, data: { blocked: true, by: hit.label || hit.id } });
              }
            });
          } catch (e) {}
          var self = this;
          setTimeout(function () {
            try {
              if (typeof self.onreadystatechange === 'function') self.onreadystatechange();
              if (typeof self.onload === 'function') self.onload();
              self.dispatchEvent(new Event('loadend'));
            } catch (e2) {}
          }, 0);
          return;
        }
        return send.apply(this, arguments);
      };
    }

    // fetch
    if (typeof window.fetch === 'function') {
      var origFetch = window.fetch;
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        var body = init && init.body ? init.body : null;
        var action = actionFromBody(body) || actionFromUrl(url);
        if (shouldBlock(url, action, 'client')) {
          var fake = fakeResponse(url);
          if (fake) return Promise.resolve(fake);
          return Promise.reject(new Error('Blocked by SpeedPulse'));
        }
        return origFetch.apply(this, arguments);
      };
    }

    // sendBeacon (Heartbeat و گزارش‌ها)
    if (navigator.sendBeacon) {
      var origBeacon = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function (url, data) {
        var action = actionFromBody(data) || actionFromUrl(url);
        if (shouldBlock(url, action, 'client')) {
          return true; // وانمود موفقیت تا WP خطا ندهد
        }
        return origBeacon(url, data);
      };
    }
  }

  function makeId(kind, url, pattern, action, type) {
    var s = [kind, url || '', pattern || '', action || '', type || ''].join('|');
    var h = 0;
    for (var i = 0; i < s.length; i++) h = ((h << 5) - h) + s.charCodeAt(i);
    return 'b' + Math.abs(h).toString(16);
  }

  function patternFromUrl(url) {
    try {
      var u = new URL(url, window.location.href);
      // مسیر + بخش‌های پایدار کوئری (بدون _: و ver)
      var keep = [];
      u.searchParams.forEach(function (v, k) {
        if (k === '_' || k === 'ver' || k === 'v' || k === 't') return;
        keep.push(k + '=' + v);
      });
      var q = keep.length ? ('?' + keep.join('&')) : '';
      return u.pathname + q;
    } catch (e) {
      return String(url || '').split('#')[0];
    }
  }

  var api = {
    getItems: function () { return items.slice(); },
    isBlocked: function (url, action) {
      return !!shouldBlock(url, action || '', 'client');
    },
    find: function (url, action) {
      return shouldBlock(url, action || '', 'client');
    },
    add: function (spec) {
      spec = spec || {};
      var kind = spec.kind || (spec.action ? 'action' : (spec.type ? 'type' : 'pattern'));
      var url = spec.url || '';
      var pattern = spec.pattern || (url ? patternFromUrl(url) : '');
      var action = spec.action || '';
      var type = spec.type || '';
      if (String(action).indexOf('speedpulse_') === 0) return null;
      var id = spec.id || makeId(kind, url, pattern, action, type);
      // حذف تکراری مشابه
      items = items.filter(function (it) { return it.id !== id; });
      var row = {
        id: id,
        kind: kind,
        url: url,
        pattern: pattern,
        action: action,
        type: type,
        label: spec.label || pattern || action || type || url,
        scope: spec.scope || 'both',
        at: Date.now()
      };
      items.unshift(row);
      if (items.length > 80) items = items.slice(0, 80);
      saveLocal();
      api.persist();
      return row;
    },
    remove: function (id) {
      items = items.filter(function (it) { return it.id !== id; });
      saveLocal();
      api.persist();
    },
    clear: function () {
      items = [];
      saveLocal();
      api.persist();
    },
    persist: function () {
      if (!CFG.ajaxUrl || !CFG.nonce) return;
      var fd = new FormData();
      fd.append('action', 'speedpulse_save_blocks');
      fd.append('_ajax_nonce', CFG.nonce);
      fd.append('items', JSON.stringify(items));
      if (navigator.sendBeacon) {
        // از beacon اصلی استفاده نکن اگر قفل شده؛ مستقیم
        try {
          var xhr = new XMLHttpRequest();
          xhr.open('POST', CFG.ajaxUrl, true);
          xhr.send(fd);
        } catch (e) {}
      } else {
        try {
          fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e2) {}
      }
    },
    patternFromUrl: patternFromUrl
  };

  loadItems();
  install();
  window.SpeedPulseBlocker = api;
})();
