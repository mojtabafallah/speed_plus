/**
 * اسپید‌پالس پرو — تعاملات پنل ادمین (فارسی)
 */
(function () {
  'use strict';

  var D = window.SpeedPulseData || {};
  var drawer, panels;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', D.nonce || '');
    Object.keys(data || {}).forEach(function (k) {
      fd.append(k, data[k]);
    });
    return fetch(D.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    }).then(function (r) { return r.json(); });
  }

  function openDrawer() {
    if (!drawer) return;
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    refreshSnapshot();
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
  }

  function switchTab(name) {
    $all('.speedpulse-tabs button').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-tab') === name);
    });
    $all('.speedpulse-tab').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-panel') === name);
    });
    if (name === 'errors') loadErrors();
    if (name === 'woo') loadWoo();
    if (name === 'queries') loadIndexes();
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderTimeline(snap) {
    var el = $('#sp-timeline');
    if (!el) return;
    var max = 1;
    (snap.timeline || []).forEach(function (r) { max = Math.max(max, r.time || 0); });
    el.innerHTML = (snap.timeline || []).map(function (r) {
      var pct = Math.min(100, ((r.time || 0) / max) * 100);
      return '<div class="sp-row"><div><strong>' + esc(r.label) + '</strong>' +
        '<div class="sp-meta">' + esc(r.source) + ' — ' + esc(r.time) + ' ms</div>' +
        '<div class="sp-bar"><span style="width:' + pct + '%"></span></div></div>' +
        '<span class="sp-badge">' + esc(Math.round(r.memory / 1048576 * 100) / 100) + ' MB</span></div>';
    }).join('') || '<p class="sp-meta">هنوز داده‌ای ثبت نشده. مانیتورینگ را روشن و صفحه را تازه‌سازی کنید.</p>';
  }

  function renderQueries(snap) {
    var el = $('#sp-queries');
    if (!el) return;
    var rows = (snap.queries || []).slice().sort(function (a, b) { return (b.time_ms || 0) - (a.time_ms || 0); }).slice(0, 40);
    el.innerHTML = '<div class="sp-list">' + rows.map(function (q) {
      var badges = '';
      if (q.slow) badges += '<span class="sp-badge warn">کند</span> ';
      if (q.n_plus_one) badges += '<span class="sp-badge danger">N+1</span> ';
      if (q.explain && q.explain.full_scan) badges += '<span class="sp-badge danger">اسکن کامل</span> ';
      return '<div class="sp-row"><div><div class="sp-sql">' + esc(q.sql) + '</div>' +
        '<div class="sp-meta">' + esc(q.source) + ' | ' + esc(q.file) + ':' + esc(q.line) + '</div>' +
        badges + '</div><strong>' + esc(q.time_ms) + ' ms</strong></div>';
    }).join('') + '</div>';

    var dups = snap.duplicates || [];
    if (dups.length) {
      el.innerHTML += '<h4>کوئری‌های تکراری</h4><div class="sp-list">' + dups.map(function (d) {
        return '<div class="sp-row"><div class="sp-sql">' + esc(d.sql) + '</div><span class="sp-badge warn">' + esc(d.count) + '×</span></div>';
      }).join('') + '</div>';
    }
  }

  function renderSources(snap) {
    var el = $('#sp-sources');
    if (!el) return;
    var attr = snap.attribution || {};
    var keys = Object.keys(attr);
    el.innerHTML = '<div class="sp-list">' + keys.map(function (k) {
      var a = attr[k];
      return '<div class="sp-row"><div><strong>' + esc(k) + '</strong>' +
        '<div class="sp-meta">هوک: ' + esc(a.hooks) + ' | کوئری: ' + esc(a.queries) + '</div></div>' +
        '<strong>' + esc(Math.round((a.cpu || 0) * 100) / 100) + ' ms</strong></div>';
    }).join('') + '</div>';

    var net = $('#sp-network');
    if (net) {
      net.innerHTML = '<div class="sp-list">' + (snap.network || []).map(function (n) {
        return '<div class="sp-row"><div><div class="sp-sql">' + esc(n.url) + '</div>' +
          '<div class="sp-meta">' + (n.blocking ? 'مسدودکننده' : 'ناهمگام') + ' | کد ' + esc(n.code) + '</div></div>' +
          '<strong>' + esc(n.time_ms) + ' ms</strong></div>';
      }).join('') + '</div>';
    }
  }

  function renderDom(dom) {
    var el = $('#sp-dom');
    if (!el) return;
    if (!dom) {
      el.innerHTML = '<p class="sp-meta">با باز کردن یک صفحه فرانت در حالت ضبط، گزارش DOM ارسال می‌شود.</p>';
      return;
    }
    var warn = dom.warn ? '<span class="sp-badge danger">بیش از حد استاندارد گوگل</span>' : '<span class="sp-badge ok">در محدوده مجاز</span>';
    el.innerHTML = '<div class="sp-row"><div>تعداد المان‌ها<div class="sp-meta">عمق درخت: ' + esc(dom.depth) + '</div></div><strong>' + esc(dom.elements) + '</strong></div>' +
      warn +
      '<h4>منابع احتمالی مسدودکننده رندر</h4><div class="sp-list">' +
      (dom.blocking || []).slice(0, 25).map(function (b) {
        return '<div class="sp-row"><div class="sp-sql">' + esc(b.href) + '</div><span class="sp-badge">' + esc(b.tag) + '</span></div>';
      }).join('') + '</div>';
  }

  function refreshSnapshot() {
    post('speedpulse_snapshot').then(function (res) {
      if (!res || !res.success) return;
      var snap = res.data.snapshot || {};
      renderTimeline(snap);
      renderQueries(snap);
      renderSources(snap);
      renderDom(res.data.dom);
    }).catch(function () {});
  }

  function loadErrors() {
    var el = $('#sp-errors');
    if (!el) return;
    el.innerHTML = '<p class="sp-meta">' + (D.i18n && D.i18n.loading ? D.i18n.loading : '…') + '</p>';
    post('speedpulse_debug_log').then(function (res) {
      if (!res.success) {
        el.innerHTML = '<p class="sp-meta">' + esc(res.data && res.data.message) + '</p>';
        return;
      }
      var cats = res.data.categories || {};
      el.innerHTML = Object.keys(cats).map(function (name) {
        var c = cats[name];
        return '<div class="sp-row"><div><strong>' + esc(name) + '</strong><div class="sp-meta">' +
          (c.samples || []).map(function (s) {
            return esc(s.message) + ' <em>(' + esc(s.fix) + ')</em>';
          }).join('<br/>') +
          '</div></div><span class="sp-badge">' + esc(c.count) + '</span></div>';
      }).join('');
    });
  }

  function loadWoo() {
    var el = $('#sp-woo');
    if (!el) return;
    post('speedpulse_woo_report').then(function (res) {
      if (!res.success) return;
      var d = res.data;
      if (!d.active) {
        el.innerHTML = '<p class="sp-meta">' + esc(d.message) + '</p>';
        return;
      }
      el.innerHTML =
        '<div class="sp-row"><div>ترنزینت منقضی ووکامرس</div><strong>' + esc(d.expired_transients) + '</strong></div>' +
        '<div class="sp-row"><div>نشست منقضی</div><strong>' + esc(d.expired_sessions) + '</strong></div>' +
        '<div class="sp-row"><div>متای یتیم</div><strong>' + esc(d.orphan_postmeta) + '</strong></div>';
    });
  }

  function loadIndexes() {
    var el = $('#sp-indexes');
    if (!el) return;
    post('speedpulse_indexes').then(function (res) {
      if (!res.success) return;
      el.innerHTML = '<div class="sp-list">' + (res.data.items || []).map(function (i) {
        var btn = i.exists
          ? '<span class="sp-badge ok">موجود</span>'
          : '<button type="button" class="button" data-apply-index="' + esc(i.name) + '">ایجاد ایندکس</button>';
        return '<div class="sp-row"><div><strong>' + esc(i.table) + '</strong><div class="sp-meta">' + esc(i.reason) + '<br/><code dir="ltr">' + esc(i.sql) + '</code></div></div>' + btn + '</div>';
      }).join('') + '</div>';
    });
  }

  function bind() {
    drawer = $('#speedpulse-drawer');
    if (!drawer) return;

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t) return;

      if (t.closest && t.closest('#wp-admin-bar-speedpulse-pro > .ab-item')) {
        e.preventDefault();
        openDrawer();
      }
      if (t.closest && t.closest('.speedpulse-toggle-recording')) {
        e.preventDefault();
        post('speedpulse_toggle').then(function (res) {
          if (res.success) alert(res.data.message);
        });
      }
      if (t.matches && t.matches('[data-sp-close]')) closeDrawer();
      if (t.matches && t.matches('.speedpulse-tabs button')) switchTab(t.getAttribute('data-tab'));
      if (t.matches && t.matches('[data-apply-index]')) {
        post('speedpulse_apply_index', { name: t.getAttribute('data-apply-index') }).then(function (res) {
          alert((res.data && res.data.message) || '');
          loadIndexes();
        });
      }
      if (t.matches && t.matches('[data-woo-clean]')) {
        post('speedpulse_woo_cleanup', { target: t.getAttribute('data-woo-clean') }).then(function (res) {
          alert((res.data && res.data.message) || '');
          loadWoo();
        });
      }
      if (t.matches && t.matches('[data-crawl]')) {
        post('speedpulse_crawler', { cmd: t.getAttribute('data-crawl') }).then(function (res) {
          var st = res.data && res.data.state ? res.data.state : {};
          var box = $('#sp-crawler');
          var bar = $('#sp-crawler-bar');
          var pct = st.total ? Math.round((st.offset || 0) / st.total * 100) : 0;
          if (bar) bar.style.width = pct + '%';
          if (box) {
            box.innerHTML = '<div class="sp-meta">وضعیت: ' + esc(st.status) + ' — ' + esc(st.offset) + '/' + esc(st.total) + '</div>' +
              '<div class="sp-list">' + (st.results || []).slice(0, 15).map(function (r) {
                return '<div class="sp-row"><div class="sp-sql">' + esc(r.url) + '</div><strong>' + esc(r.ttfb_ms) + ' ms</strong></div>';
              }).join('') + '</div>';
          }
        });
      }
    });

    var refreshLog = $('#sp-refresh-log');
    if (refreshLog) refreshLog.addEventListener('click', loadErrors);
    var clearLog = $('#sp-clear-log');
    if (clearLog) clearLog.addEventListener('click', function () {
      post('speedpulse_clear_log').then(function (res) {
        alert((res.data && res.data.message) || '');
        loadErrors();
      });
    });

    var stress = $('#sp-stress-run');
    if (stress) stress.addEventListener('click', function () {
      var out = $('#sp-stress-result');
      if (out) out.innerHTML = '<p class="sp-meta">در حال اجرای تست فشار…</p>';
      post('speedpulse_stress', {
        concurrency: ($('#sp-stress-c') || {}).value || 50,
        duration: ($('#sp-stress-d') || {}).value || 5
      }).then(function (res) {
        if (!out) return;
        var d = res.data || {};
        out.innerHTML =
          '<div class="sp-row"><div>میانگین TTFB</div><strong>' + esc(d.avg_ttfb_ms) + ' ms</strong></div>' +
          '<div class="sp-row"><div>P95</div><strong>' + esc(d.p95_ttfb_ms) + ' ms</strong></div>' +
          '<div class="sp-row"><div>افت TTFB</div><strong>' + esc(d.ttfb_drop_pct) + '٪</strong></div>' +
          '<div class="sp-row"><div>پایداری</div><strong>' + (d.stable ? 'پایدار' : 'ناسپایدار') + '</strong></div>';
      });
    });

    var aiRun = $('#sp-ai-run');
    if (aiRun) aiRun.addEventListener('click', function () {
      var out = $('#sp-ai-out');
      if (out) out.textContent = 'در حال ارسال داده به هوش مصنوعی…';
      post('speedpulse_ai_analyze').then(function (res) {
        if (out) out.textContent = (res.data && (res.data.content || res.data.message)) || 'خطا';
      });
    });

    var patchGen = $('#sp-patch-gen');
    if (patchGen) patchGen.addEventListener('click', function () {
      post('speedpulse_patch_generate').then(function (res) {
        var d = res.data || {};
        var code = $('#sp-patch-code');
        var diff = $('#sp-patch-diff');
        if (code) code.value = d.code || '';
        if (diff) diff.textContent = d.diff || d.message || '';
      });
    });

    var patchSave = $('#sp-patch-save');
    if (patchSave) patchSave.addEventListener('click', function () {
      var code = ($('#sp-patch-code') || {}).value || '';
      post('speedpulse_patch_save', { code: code }).then(function (res) {
        alert((res.data && res.data.message) || '');
      });
    });

    var canary = $('#sp-patch-canary');
    if (canary) canary.addEventListener('click', function () {
      post('speedpulse_patch_canary').then(function (res) {
        alert((res.data && res.data.message) || (res.data && res.data.message) || 'نتیجه Canary');
      });
    });

    var rollback = $('#sp-patch-rollback');
    if (rollback) rollback.addEventListener('click', function () {
      post('speedpulse_patch_rollback').then(function (res) {
        alert((res.data && res.data.message) || '');
      });
    });

    var form = $('#speedpulse-settings-form');
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form);
        var payload = {};
        fd.forEach(function (v, k) { payload[k] = v; });
        ['capture_queries', 'capture_hooks', 'capture_network'].forEach(function (k) {
          if (!payload[k]) payload[k] = '';
        });
        post('speedpulse_save_settings', payload).then(function (res) {
          var msg = $('#speedpulse-settings-msg');
          if (msg) msg.textContent = (res.data && res.data.message) || '';
        });
      });
    }

    var openBtn = $('#speedpulse-open-drawer');
    if (openBtn) openBtn.addEventListener('click', openDrawer);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
