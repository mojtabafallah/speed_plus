/**
 * اسپید‌پالس پرو — تعاملات پنل ادمین (فارسی)
 * لودینگ AJAX + مودال پیام به‌جای alert
 */
(function () {
  'use strict';

  var D = window.SpeedPulseData || {};
  var drawer;
  var ajaxBusy = 0;
  var modalTimer = null;
  var lastSnap = null;
  var lastDom = null;
  var lastBrowser = D.browserMetrics || null;
  var lastTips = [];

  var BREAKDOWN_MAP = {
    queries: {
      tab: 'queries',
      scroll: 'sp-queries',
      title: 'جزئیات کوئری‌های دیتابیس',
      hint: 'لیست کامل SQLها، کوئری‌های کند و تکراری در تب کوئری‌هاست.'
    },
    network: {
      tab: 'sources',
      scroll: 'sp-network',
      title: 'جزئیات درخواست‌های شبکه',
      hint: 'درخواست‌های HTTP مسدودکننده در بخش سهم منابع نمایش داده می‌شوند.'
    },
    hooks: {
      tab: 'sources',
      scroll: 'sp-sources',
      title: 'جزئیات هوک‌های کلیدی',
      hint: 'سهم افزونه‌ها/قالب و هوک‌ها در تب سهم منابع قابل مشاهده است.'
    },
    other: {
      tab: 'sources',
      scroll: 'sp-sources',
      title: 'سایر پردازش PHP',
      hint: 'باقی‌مانده زمان لود؛ سهم منابع را برای یافتن کندترین افزونه ببینید.'
    },
    cron: {
      tab: 'tools',
      scroll: 'sp-crawler',
      title: 'رویدادهای زمان‌بندی‌شده',
      hint: 'برای کارهای پس‌زمینه، وضعیت کرون و ابزارها را بررسی کنید.'
    }
  };

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $all(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * واحد هوشمند: زیر ۱ ثانیه → میلی‌ثانیه، از ۱ ثانیه به بالا → ثانیه
   * @param {number|string} ms
   * @returns {string}
   */
  function fmtTime(ms) {
    var n = parseFloat(ms);
    if (!isFinite(n) || n < 0) n = 0;
    if (n >= 60000) {
      var min = Math.floor(n / 60000);
      var remSec = (n % 60000) / 1000;
      return min + ' دقیقه و ' + remSec.toFixed(2) + ' ثانیه';
    }
    if (n >= 1000) {
      return (n / 1000).toFixed(3) + ' ثانیه';
    }
    return n.toFixed(2) + ' میلی‌ثانیه';
  }

  function ensureUi() {
    if ($('#speedpulse-ajax-loader')) return;

    var loader = document.createElement('div');
    loader.id = 'speedpulse-ajax-loader';
    loader.className = 'sp-ajax-loader';
    loader.setAttribute('aria-hidden', 'true');
    loader.innerHTML =
      '<div class="sp-ajax-loader__card" role="status" aria-live="polite">' +
        '<div class="sp-spinner" aria-hidden="true"></div>' +
        '<div class="sp-ajax-loader__text">' +
          '<strong id="sp-loader-title">لطفاً صبر کنید</strong>' +
          '<span id="sp-loader-msg">در حال دریافت اطلاعات…</span>' +
        '</div>' +
      '</div>';
    document.body.appendChild(loader);

    var modal = document.createElement('div');
    modal.id = 'speedpulse-modal';
    modal.className = 'sp-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="sp-modal__backdrop" data-sp-modal-close></div>' +
      '<div class="sp-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sp-modal-title">' +
        '<div class="sp-modal__icon" id="sp-modal-icon"></div>' +
        '<h3 id="sp-modal-title" class="sp-modal__title"></h3>' +
        '<div id="sp-modal-body" class="sp-modal__body"></div>' +
        '<div class="sp-modal__actions">' +
          '<button type="button" class="sp-modal__btn sp-modal__btn--ghost" data-sp-modal-close>بستن</button>' +
          '<button type="button" class="sp-modal__btn" id="sp-modal-action" hidden>رفتن به بخش</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target && e.target.matches && e.target.matches('[data-sp-modal-close]')) {
        hideModal();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hideModal();
    });
  }

  function showLoader(message, title) {
    ensureUi();
    ajaxBusy++;
    var el = $('#speedpulse-ajax-loader');
    var msg = $('#sp-loader-msg');
    var ttl = $('#sp-loader-title');
    if (msg) msg.textContent = message || 'در حال دریافت اطلاعات…';
    if (ttl) ttl.textContent = title || 'لطفاً صبر کنید';
    el.classList.add('is-visible');
    el.setAttribute('aria-hidden', 'false');
    document.documentElement.classList.add('sp-ajax-busy');
  }

  function hideLoader() {
    ajaxBusy = Math.max(0, ajaxBusy - 1);
    if (ajaxBusy > 0) return;
    var el = $('#speedpulse-ajax-loader');
    if (!el) return;
    el.classList.remove('is-visible');
    el.setAttribute('aria-hidden', 'true');
    document.documentElement.classList.remove('sp-ajax-busy');
  }

  /**
   * مودال خوش‌فرم به‌جای alert
   * @param {string} message
   * @param {{type?: string, title?: string, autoClose?: number}} opts
   */
  function notify(message, opts) {
    ensureUi();
    opts = opts || {};
    var type = opts.type || 'info';
    var title = opts.title || ({
      success: 'انجام شد',
      error: 'خطا',
      warn: 'توجه',
      info: 'پیام سیستم'
    }[type] || 'پیام سیستم');

    var icons = {
      success: '✓',
      error: '!',
      warn: '⚠',
      info: 'ℹ'
    };

    var modal = $('#speedpulse-modal');
    var icon = $('#sp-modal-icon');
    var ttl = $('#sp-modal-title');
    var body = $('#sp-modal-body');
    var actionBtn = $('#sp-modal-action');

    modal.className = 'sp-modal is-open sp-modal--' + type;
    if (icon) icon.textContent = icons[type] || 'ℹ';
    if (ttl) ttl.textContent = title;
    if (body) {
      if (opts.html) {
        body.innerHTML = message || '';
      } else {
        body.textContent = message || '';
      }
    }
    if (actionBtn) {
      actionBtn.onclick = null;
      if (opts.action && typeof opts.action.onClick === 'function') {
        actionBtn.hidden = false;
        actionBtn.textContent = opts.action.label || 'رفتن به بخش';
        actionBtn.onclick = function () {
          hideModal();
          opts.action.onClick();
        };
      } else {
        actionBtn.hidden = true;
      }
    }
    modal.setAttribute('aria-hidden', 'false');

    if (modalTimer) clearTimeout(modalTimer);
    if (opts.autoClose === false) {
      return;
    }
    if (opts.autoClose !== false && !opts.action) {
      var ms = typeof opts.autoClose === 'number' ? opts.autoClose : 4200;
      modalTimer = setTimeout(hideModal, ms);
    }
  }

  function hideModal() {
    var modal = $('#speedpulse-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    var actionBtn = $('#sp-modal-action');
    if (actionBtn) {
      actionBtn.hidden = true;
      actionBtn.onclick = null;
    }
    if (modalTimer) {
      clearTimeout(modalTimer);
      modalTimer = null;
    }
  }

  function switchTab(name, scrollId) {
    $all('.speedpulse-tabs button').forEach(function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-tab') === name);
    });
    $all('.speedpulse-tab').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-panel') === name);
    });
    if (name === 'errors') loadErrors();
    if (name === 'woo') loadWoo();
    if (name === 'queries') loadIndexes();
    if (name === 'tips') renderTips(lastTips);

    if (scrollId) {
      setTimeout(function () {
        var target = document.getElementById(scrollId);
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          target.classList.add('sp-flash');
          setTimeout(function () { target.classList.remove('sp-flash'); }, 1400);
        }
      }, 80);
    }
  }

  function openBreakdownDetail(key) {
    var map = BREAKDOWN_MAP[key];
    if (!map) return;
    var snap = lastSnap || {};
    var bd = snap.time_breakdown || {};
    var item = null;
    (bd.items || []).forEach(function (i) {
      if (i.key === key) item = i;
    });

    var extra = '';
    if (key === 'queries') {
      var qs = (snap.queries || []).slice().sort(function (a, b) { return (b.time_ms || 0) - (a.time_ms || 0); }).slice(0, 5);
      if (qs.length) {
        extra = '<div class="sp-detail-list"><div class="sp-detail-label">۵ کوئری کندتر:</div>' +
          qs.map(function (q) {
            return '<div class="sp-detail-item"><code dir="ltr">' + esc((q.sql || '').slice(0, 120)) +
              '</code><span>' + esc(fmtTime(q.time_ms)) + '</span></div>';
          }).join('') + '</div>';
      }
    } else if (key === 'network') {
      var nets = (snap.network || []).slice().sort(function (a, b) { return (b.time_ms || 0) - (a.time_ms || 0); }).slice(0, 5);
      if (nets.length) {
        extra = '<div class="sp-detail-list"><div class="sp-detail-label">درخواست‌های شبکه:</div>' +
          nets.map(function (n) {
            return '<div class="sp-detail-item"><code dir="ltr">' + esc((n.url || '').slice(0, 100)) +
              '</code><span>' + esc(fmtTime(n.time_ms)) + '</span></div>';
          }).join('') + '</div>';
      } else {
        extra = '<p class="sp-meta">در این درخواست، شبکه مسدودکننده ثبت نشده است.</p>';
      }
    } else if (key === 'hooks' || key === 'other') {
      var attr = snap.attribution || {};
      var keys = Object.keys(attr).slice(0, 6);
      if (keys.length) {
        extra = '<div class="sp-detail-list"><div class="sp-detail-label">کندترین منابع:</div>' +
          keys.map(function (k) {
            var a = attr[k] || {};
            return '<div class="sp-detail-item"><span>' + esc(k) + '</span><span>' +
              esc(fmtTime(a.cpu || 0)) + '</span></div>';
          }).join('') + '</div>';
      }
    } else if (key === 'cron') {
      var cron = snap.cron || [];
      if (cron.length) {
        extra = '<div class="sp-detail-list"><div class="sp-detail-label">رویدادهای ثبت‌شده:</div>' +
          cron.slice(0, 6).map(function (c) {
            return '<div class="sp-detail-item"><span>' + esc(c.type || c.hook || 'رویداد') +
              '</span><span>' + esc(c.note || '') + '</span></div>';
          }).join('') + '</div>';
      }
    }

    var stats = item
      ? '<div class="sp-detail-stats">' +
          '<div><b>' + esc(item.human) + '</b><span>زمان</span></div>' +
          '<div><b>' + esc(item.percent) + '٪</b><span>از کل</span></div>' +
          '<div><b>' + esc(item.count || 0) + '</b><span>تعداد</span></div>' +
        '</div>'
      : '';

    var html = '<p>' + esc(map.hint) + '</p>' +
      (item && item.note ? '<p class="sp-meta">' + esc(item.note) + '</p>' : '') +
      stats + extra +
      '<p class="sp-click-hint">برای مشاهده کامل، به بخش مربوطه بروید.</p>';

    notify(html, {
      type: 'info',
      title: map.title,
      html: true,
      autoClose: false,
      action: {
        label: 'رفتن به بخش مربوطه',
        onClick: function () {
          switchTab(map.tab, map.scroll);
        }
      }
    });
  }

  function openTimelineDetail(index) {
    var snap = lastSnap || {};
    var row = (snap.timeline || [])[index];
    if (!row) return;

    var prev = index > 0 ? (snap.timeline || [])[index - 1] : null;
    var delta = prev ? Math.max(0, (row.time || 0) - (prev.time || 0)) : (row.time || 0);
    var html =
      '<div class="sp-detail-stats">' +
        '<div><b>' + esc(fmtTime(row.time)) + '</b><span>از شروع درخواست</span></div>' +
        '<div><b>' + esc(fmtTime(delta)) + '</b><span>فاصله از مرحله قبل</span></div>' +
        '<div><b>' + esc(Math.round((row.memory || 0) / 1048576 * 100) / 100) + ' MB</b><span>رشد حافظه</span></div>' +
      '</div>' +
      '<p><strong>منبع:</strong> ' + esc(row.source || '—') + '</p>' +
      '<p class="sp-meta">این نقطه یکی از مراحل چرخه حیات وردپرس است. برای یافتن گلوگاه، سهم منابع و کوئری‌ها را ببینید.</p>';

    var gotoTab = 'sources';
    var gotoScroll = 'sp-sources';
    if (String(row.label || '').indexOf('ووکامرس') !== -1) {
      gotoTab = 'woo';
      gotoScroll = 'sp-woo';
    }

    notify(html, {
      type: 'info',
      title: row.label || 'جزئیات مرحله',
      html: true,
      autoClose: false,
      action: {
        label: gotoTab === 'woo' ? 'رفتن به جراح ووکامرس' : 'رفتن به سهم منابع',
        onClick: function () {
          switchTab(gotoTab, gotoScroll);
        }
      }
    });
  }

  function setPanelLoading(el, text) {
    if (!el) return;
    el.classList.add('is-loading');
    el.innerHTML =
      '<div class="sp-panel-loading">' +
        '<div class="sp-spinner sp-spinner--sm"></div>' +
        '<span>' + esc(text || 'در حال بارگذاری…') + '</span>' +
      '</div>';
  }

  function clearPanelLoading(el) {
    if (el) el.classList.remove('is-loading');
  }

  /**
   * @param {string} action
   * @param {Object=} data
   * @param {{loading?: boolean|string, loadingTitle?: string, target?: Element|null, targetText?: string}=} options
   */
  function post(action, data, options) {
    options = options || {};
    var useGlobal = options.loading !== false;
    var loadingMsg = typeof options.loading === 'string' ? options.loading : 'در حال ارسال درخواست…';

    if (useGlobal) {
      showLoader(loadingMsg, options.loadingTitle || 'لطفاً صبر کنید');
    }
    if (options.target) {
      setPanelLoading(options.target, options.targetText || loadingMsg);
    }

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
    })
      .then(function (r) {
        return r.json().catch(function () {
          throw new Error('پاسخ نامعتبر از سرور');
        });
      })
      .catch(function (err) {
        notify((err && err.message) ? err.message : 'ارتباط با سرور برقرار نشد.', {
          type: 'error',
          title: 'خطای ارتباط'
        });
        throw err;
      })
      .finally(function () {
        if (useGlobal) hideLoader();
        if (options.target) clearPanelLoading(options.target);
      });
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

  function renderTips(tips) {
    var el = $('#sp-tips');
    if (!el) return;
    tips = tips || lastTips || [];
    if (!tips.length) {
      el.innerHTML = '<p class="sp-meta">هنوز پیشنهادی نیست. ضبط را روشن کنید، صفحه کند را کامل لود کنید، سپس اینجا را به‌روز کنید.</p>';
      return;
    }

    el.innerHTML = tips.map(function (t) {
      var sev = t.severity || 'info';
      var badge = sev === 'critical' ? 'danger' : (sev === 'warn' ? 'warn' : (sev === 'ok' ? 'ok' : ''));
      var steps = (t.steps || []).map(function (s) {
        return '<li>' + esc(s) + '</li>';
      }).join('');
      return '<article class="sp-tip sp-tip--' + esc(sev) + '">' +
        '<div class="sp-tip__head">' +
          '<strong>' + esc(t.title) + '</strong>' +
          '<span class="sp-badge ' + badge + '">' + esc({
            critical: 'بحرانی',
            warn: 'هشدار',
            ok: 'عالی',
            info: 'راهنما'
          }[sev] || 'راهنما') + '</span>' +
        '</div>' +
        '<p class="sp-meta">' + esc(t.why) + '</p>' +
        '<ol class="sp-tip__steps">' + steps + '</ol>' +
        (t.related ? '<button type="button" class="button sp-tip-goto" data-tip-goto="' + esc(t.related) + '">رفتن به بخش مرتبط</button>' : '') +
      '</article>';
    }).join('');
  }

  function renderBrowser(browser) {
    var el = $('#sp-browser');
    if (!el) return;
    browser = browser || lastBrowser;
    if (!browser || !browser.browser_wall_ms) {
      el.innerHTML =
        '<div class="sp-browser-empty">' +
          '<p><strong>هنوز متریک مرورگر نرسیده است.</strong></p>' +
          '<p class="sp-meta">پس از روشن بودن ضبط، ۱۵–۳۵ ثانیه در همین صفحه بمانید تا درخواست‌های Network (مثل wp-json ووکامرس) هم جمع شوند. سپس پنل را دوباره باز کنید.</p>' +
          '<p class="sp-meta">توجه: عدد «۳ ثانیه» فقط زمان ساخت HTML در سرور است؛ تب Network مرورگر مجموع همه درخواست‌های بعدی را هم نشان می‌دهد.</p>' +
        '</div>';
      return;
    }

    var doc = browser.server_document || {};
    var types = (browser.by_type || []).map(function (t) {
      return '<div class="sp-row"><div><strong>' + esc(t.label) + '</strong>' +
        '<div class="sp-meta">' + esc(t.count) + ' درخواست — جمع نسبی ' + esc(t.human_sum) + '</div></div>' +
        '<div class="sp-breakdown-nums"><strong>' + esc(t.human_max || fmtTime(t.max_ms)) + '</strong>' +
        '<span class="sp-badge warn">کندترین</span></div></div>';
    }).join('');

    var slow = (browser.slowest || []).slice(0, 12).map(function (s) {
      return '<div class="sp-row"><div><div class="sp-sql">' + esc(s.name) + '</div>' +
        '<div class="sp-meta">' + esc(s.type) + (s.waiting_ms ? (' | انتظار سرور: ' + fmtTime(s.waiting_ms)) : '') + '</div></div>' +
        '<strong>' + esc(s.human || fmtTime(s.duration_ms)) + '</strong></div>';
    }).join('');

    el.innerHTML =
      '<div class="sp-browser-hero">' +
        '<div class="sp-browser-hero__label">زمان واقعی تجربه کاربر (مرورگر / Network)</div>' +
        '<div class="sp-browser-hero__value">' + esc(browser.browser_wall_human || fmtTime(browser.browser_wall_ms)) + '</div>' +
        '<p class="sp-meta">' + esc(browser.note_fa || '') + '</p>' +
        '<div class="sp-detail-stats">' +
          '<div><b>' + esc(doc.human_ttfb || fmtTime(doc.ttfb_ms)) + '</b><span>TTFB سند اصلی</span></div>' +
          '<div><b>' + esc(doc.human_dom || fmtTime(doc.dom_content_ms)) + '</b><span>DOMContentLoaded</span></div>' +
          '<div><b>' + esc(doc.human_load || fmtTime(doc.load_event_ms)) + '</b><span>رویداد Load</span></div>' +
        '</div>' +
        '<div class="sp-meta">تعداد منابع ثبت‌شده: ' + esc(browser.resource_count || 0) + '</div>' +
      '</div>' +
      '<h4>دسته‌بندی منابع مرورگر</h4><div class="sp-list">' + (types || '<p class="sp-meta">موردی نیست</p>') + '</div>' +
      '<h4>کندترین درخواست‌های Network</h4><div class="sp-list">' + (slow || '<p class="sp-meta">موردی نیست</p>') + '</div>' +
      (lastTips && lastTips.length
        ? '<div class="sp-browser-tips-link"><button type="button" class="button button-primary" data-tab-jump="tips">مشاهده راهکارهای پیشنهادی (' + lastTips.length + ')</button></div>'
        : '');
  }

  function renderBreakdown(snap) {
    var el = $('#sp-breakdown');
    if (!el) return;
    var bd = snap.time_breakdown || {};
    var items = bd.items || [];
    if (!items.length) {
      el.innerHTML = '<p class="sp-meta">پس از روشن کردن ضبط و تازه‌سازی صفحه، خلاصه زمان اینجا نمایش داده می‌شود.</p>';
      return;
    }

    var colors = {
      queries: '#3dba9c',
      network: '#e0a45c',
      hooks: '#6ea8fe',
      other: '#93a4b8',
      cron: '#c084fc'
    };

    var stacked = items.filter(function (i) {
      return i.key === 'queries' || i.key === 'network' || i.key === 'other';
    }).map(function (i) {
      var w = Math.max(0, Math.min(100, i.percent || 0));
      return '<button type="button" class="sp-stack-seg" data-sp-breakdown="' + esc(i.key) + '" style="width:' + w + '%;background:' + (colors[i.key] || '#3dba9c') + '" title="' + esc(i.label) + '"></button>';
    }).join('');

    var rows = items.map(function (i) {
      var count = i.count ? (' — ' + i.count + ' مورد') : '';
      return '<button type="button" class="sp-row sp-breakdown-row sp-clickable" data-sp-breakdown="' + esc(i.key) + '">' +
        '<div><strong>' + esc(i.label) + '</strong>' +
        '<div class="sp-meta">' + esc(i.note || '') + count + '</div>' +
        '<div class="sp-bar"><span style="width:' + Math.min(100, i.percent || 0) + '%;background:' + (colors[i.key] || '#3dba9c') + '"></span></div>' +
        '<div class="sp-click-hint">برای جزئیات یا رفتن به بخش کلیک کنید</div></div>' +
        '<div class="sp-breakdown-nums"><strong>' + esc(i.human) + '</strong>' +
        '<span class="sp-badge">' + esc(i.percent) + '٪</span></div></button>';
    }).join('');

    el.innerHTML =
      '<div class="sp-breakdown-total">' +
        '<div class="sp-breakdown-total__label">کل زمان مصرف‌شده برای لود</div>' +
        '<div class="sp-breakdown-total__value">' + esc(bd.total_human || snap.total_human || '—') + '</div>' +
        '<div class="sp-meta">' + esc(bd.summary_fa || '') + '</div>' +
        '<div class="sp-breakdown-stack">' + stacked + '</div>' +
      '</div>' +
      '<div class="sp-list">' + rows + '</div>';
  }

  function renderTimeline(snap) {
    renderBrowser(lastBrowser);
    renderBreakdown(snap);
    var el = $('#sp-timeline');
    if (!el) return;
    var max = 1;
    (snap.timeline || []).forEach(function (r) { max = Math.max(max, r.time || 0); });
    el.innerHTML = (snap.timeline || []).map(function (r, idx) {
      var pct = Math.min(100, ((r.time || 0) / max) * 100);
      return '<button type="button" class="sp-row sp-clickable" data-sp-timeline="' + idx + '">' +
        '<div><strong>' + esc(r.label) + '</strong>' +
        '<div class="sp-meta">' + esc(r.source) + ' — ' + esc(fmtTime(r.time)) + '</div>' +
        '<div class="sp-bar"><span style="width:' + pct + '%"></span></div>' +
        '<div class="sp-click-hint">کلیک برای جزئیات این مرحله</div></div>' +
        '<span class="sp-badge">' + esc(Math.round(r.memory / 1048576 * 100) / 100) + ' MB</span></button>';
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
        badges + '</div><strong>' + esc(fmtTime(q.time_ms)) + '</strong></div>';
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
        '<strong>' + esc(fmtTime(a.cpu || 0)) + '</strong></div>';
    }).join('') + '</div>';

    var net = $('#sp-network');
    if (net) {
      net.innerHTML = '<div class="sp-list">' + (snap.network || []).map(function (n) {
        return '<div class="sp-row"><div><div class="sp-sql">' + esc(n.url) + '</div>' +
          '<div class="sp-meta">' + (n.blocking ? 'مسدودکننده' : 'ناهمگام') + ' | کد ' + esc(n.code) + '</div></div>' +
          '<strong>' + esc(fmtTime(n.time_ms)) + '</strong></div>';
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
    var targets = [$('#sp-breakdown'), $('#sp-timeline'), $('#sp-queries'), $('#sp-sources')].filter(Boolean);
    targets.forEach(function (el) {
      setPanelLoading(el, 'در حال دریافت خط زمان و آمار…');
    });

    post('speedpulse_snapshot', {}, {
      loading: 'در حال دریافت داده‌های پروفایلینگ…',
      loadingTitle: 'بارگذاری خط زمان'
    }).then(function (res) {
      if (!res || !res.success) {
        notify((res && res.data && res.data.message) || 'دریافت اسنپ‌شات ناموفق بود.', { type: 'error' });
        return;
      }
      var snap = res.data.snapshot || {};
      lastSnap = snap;
      lastDom = res.data.dom || null;
      if (res.data.browser) {
        lastBrowser = res.data.browser;
        D.browserMetrics = lastBrowser;
      }
      if (res.data.recommendations) {
        lastTips = res.data.recommendations;
      }
      renderTimeline(snap);
      renderTips(lastTips);
      renderQueries(snap);
      renderSources(snap);
      renderDom(res.data.dom);
    }).catch(function () {});
  }

  function loadErrors() {
    var el = $('#sp-errors');
    post('speedpulse_debug_log', {}, {
      loading: 'در حال خواندن و دسته‌بندی debug.log…',
      loadingTitle: 'تحلیل لاگ',
      target: el,
      targetText: 'در حال تحلیل خطاها…'
    }).then(function (res) {
      if (!el) return;
      if (!res.success) {
        el.innerHTML = '<p class="sp-meta">' + esc(res.data && res.data.message) + '</p>';
        notify((res.data && res.data.message) || 'خواندن لاگ ناموفق بود.', { type: 'error' });
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
    }).catch(function () {});
  }

  function loadWoo() {
    var el = $('#sp-woo');
    post('speedpulse_woo_report', {}, {
      loading: 'در حال بررسی وضعیت ووکامرس…',
      loadingTitle: 'جراح ووکامرس',
      target: el,
      targetText: 'در حال دریافت گزارش ووکامرس…'
    }).then(function (res) {
      if (!el || !res.success) return;
      var d = res.data;
      if (!d.active) {
        el.innerHTML = '<p class="sp-meta">' + esc(d.message) + '</p>';
        return;
      }
      el.innerHTML =
        '<div class="sp-row"><div>ترنزینت منقضی ووکامرس</div><strong>' + esc(d.expired_transients) + '</strong></div>' +
        '<div class="sp-row"><div>نشست منقضی</div><strong>' + esc(d.expired_sessions) + '</strong></div>' +
        '<div class="sp-row"><div>متای یتیم</div><strong>' + esc(d.orphan_postmeta) + '</strong></div>';
    }).catch(function () {});
  }

  function loadIndexes() {
    var el = $('#sp-indexes');
    post('speedpulse_indexes', {}, {
      loading: 'در حال تحلیل ایندکس‌های پیشنهادی…',
      loadingTitle: 'بهینه‌ساز ایندکس',
      target: el,
      targetText: 'در حال دریافت پیشنهاد ایندکس…'
    }).then(function (res) {
      if (!el || !res.success) return;
      el.innerHTML = '<div class="sp-list">' + (res.data.items || []).map(function (i) {
        var btn = i.exists
          ? '<span class="sp-badge ok">موجود</span>'
          : '<button type="button" class="button" data-apply-index="' + esc(i.name) + '">ایجاد ایندکس</button>';
        return '<div class="sp-row"><div><strong>' + esc(i.table) + '</strong><div class="sp-meta">' + esc(i.reason) + '<br/><code dir="ltr">' + esc(i.sql) + '</code></div></div>' + btn + '</div>';
      }).join('') + '</div>';
    }).catch(function () {});
  }

  function bind() {
    ensureUi();
    drawer = $('#speedpulse-drawer');
    if (!drawer) return;

    if (D.snapshot && typeof D.snapshot === 'object') {
      lastSnap = D.snapshot;
    }
    if (D.browserMetrics) {
      lastBrowser = D.browserMetrics;
    }

    window.addEventListener('speedpulse:browser-metrics', function (ev) {
      if (ev && ev.detail) {
        lastBrowser = ev.detail;
        D.browserMetrics = ev.detail;
        if (drawer && drawer.classList.contains('is-open')) {
          renderBrowser(lastBrowser);
        }
      }
    });

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t) return;

      if (t.closest && t.closest('#wp-admin-bar-speedpulse-pro > .ab-item')) {
        e.preventDefault();
        openDrawer();
      }
      if (t.closest && t.closest('.speedpulse-toggle-recording')) {
        e.preventDefault();
        post('speedpulse_toggle', {}, {
          loading: 'در حال تغییر وضعیت مانیتورینگ…',
          loadingTitle: 'مانیتورینگ'
        }).then(function (res) {
          if (res.success) {
            notify(res.data.message, {
              type: res.data.enabled ? 'success' : 'warn',
              title: res.data.enabled ? 'مانیتورینگ روشن شد' : 'مانیتورینگ خاموش شد'
            });
          } else {
            notify((res.data && res.data.message) || 'تغییر وضعیت ناموفق بود.', { type: 'error' });
          }
        }).catch(function () {});
      }
      if (t.matches && t.matches('[data-sp-close]')) closeDrawer();
      if (t.matches && t.matches('.speedpulse-tabs button')) switchTab(t.getAttribute('data-tab'));

      var breakdownEl = t.closest ? t.closest('[data-sp-breakdown]') : null;
      if (breakdownEl) {
        e.preventDefault();
        openBreakdownDetail(breakdownEl.getAttribute('data-sp-breakdown'));
        return;
      }
      var timelineEl = t.closest ? t.closest('[data-sp-timeline]') : null;
      if (timelineEl) {
        e.preventDefault();
        openTimelineDetail(parseInt(timelineEl.getAttribute('data-sp-timeline'), 10) || 0);
        return;
      }
      var tipGoto = t.closest ? t.closest('[data-tip-goto]') : null;
      if (tipGoto) {
        e.preventDefault();
        switchTab(tipGoto.getAttribute('data-tip-goto'));
        return;
      }
      var tabJump = t.closest ? t.closest('[data-tab-jump]') : null;
      if (tabJump) {
        e.preventDefault();
        switchTab(tabJump.getAttribute('data-tab-jump'));
        return;
      }

      if (t.matches && t.matches('[data-apply-index]')) {
        post('speedpulse_apply_index', { name: t.getAttribute('data-apply-index') }, {
          loading: 'در حال ایجاد ایندکس…',
          loadingTitle: 'ایندکس دیتابیس'
        }).then(function (res) {
          notify((res.data && res.data.message) || '', {
            type: res.success ? 'success' : 'error',
            title: res.success ? 'ایندکس' : 'خطا'
          });
          loadIndexes();
        }).catch(function () {});
      }
      if (t.matches && t.matches('[data-woo-clean]')) {
        post('speedpulse_woo_cleanup', { target: t.getAttribute('data-woo-clean') }, {
          loading: 'در حال پاک‌سازی دیتابیس ووکامرس…',
          loadingTitle: 'پاک‌سازی'
        }).then(function (res) {
          notify((res.data && res.data.message) || '', {
            type: res.success ? 'success' : 'error'
          });
          loadWoo();
        }).catch(function () {});
      }
      if (t.matches && t.matches('[data-crawl]')) {
        var cmd = t.getAttribute('data-crawl');
        post('speedpulse_crawler', { cmd: cmd }, {
          loading: 'در حال اجرای فرمان خزش‌گر…',
          loadingTitle: 'خزش‌گر سایت',
          target: $('#sp-crawler'),
          targetText: 'در حال به‌روزرسانی وضعیت خزش…'
        }).then(function (res) {
          if (res.data && res.data.message) {
            notify(res.data.message, { type: 'success', title: 'خزش‌گر' });
          }
          var st = res.data && res.data.state ? res.data.state : {};
          var box = $('#sp-crawler');
          var bar = $('#sp-crawler-bar');
          var pct = st.total ? Math.round((st.offset || 0) / st.total * 100) : 0;
          if (bar) bar.style.width = pct + '%';
          if (box) {
            box.innerHTML = '<div class="sp-meta">وضعیت: ' + esc(st.status) + ' — ' + esc(st.offset) + '/' + esc(st.total) + '</div>' +
              '<div class="sp-list">' + (st.results || []).slice(0, 15).map(function (r) {
                return '<div class="sp-row"><div class="sp-sql">' + esc(r.url) + '</div><strong>' + esc(fmtTime(r.ttfb_ms)) + '</strong></div>';
              }).join('') + '</div>';
          }
        }).catch(function () {});
      }
    });

    var refreshLog = $('#sp-refresh-log');
    if (refreshLog) refreshLog.addEventListener('click', loadErrors);
    var refreshTips = $('#sp-refresh-tips');
    if (refreshTips) refreshTips.addEventListener('click', function () {
      refreshSnapshot();
      switchTab('tips');
      notify('راهکارها بر اساس آخرین داده سرور و مرورگر به‌روز شد.', {
        type: 'success',
        title: 'راهکارها'
      });
    });
    var clearLog = $('#sp-clear-log');
    if (clearLog) clearLog.addEventListener('click', function () {
      post('speedpulse_clear_log', {}, {
        loading: 'در حال پاک‌سازی فایل لاگ…',
        loadingTitle: 'پاک‌سازی لاگ'
      }).then(function (res) {
        notify((res.data && res.data.message) || '', {
          type: res.success ? 'success' : 'error'
        });
        loadErrors();
      }).catch(function () {});
    });

    var stress = $('#sp-stress-run');
    if (stress) stress.addEventListener('click', function () {
      var out = $('#sp-stress-result');
      post('speedpulse_stress', {
        concurrency: ($('#sp-stress-c') || {}).value || 50,
        duration: ($('#sp-stress-d') || {}).value || 5
      }, {
        loading: 'در حال اجرای تست فشار — ممکن است چند ثانیه طول بکشد…',
        loadingTitle: 'تست فشار',
        target: out,
        targetText: 'در حال شبیه‌سازی ترافیک همزمان…'
      }).then(function (res) {
        if (!out) return;
        var d = res.data || {};
        out.innerHTML =
          '<div class="sp-row"><div>میانگین TTFB</div><strong>' + esc(fmtTime(d.avg_ttfb_ms)) + '</strong></div>' +
          '<div class="sp-row"><div>P95</div><strong>' + esc(fmtTime(d.p95_ttfb_ms)) + '</strong></div>' +
          '<div class="sp-row"><div>افت TTFB</div><strong>' + esc(d.ttfb_drop_pct) + '٪</strong></div>' +
          '<div class="sp-row"><div>پایداری</div><strong>' + (d.stable ? 'پایدار' : 'ناسپایدار') + '</strong></div>';
        notify(d.message || 'تست فشار به پایان رسید.', {
          type: d.stable ? 'success' : 'warn',
          title: 'نتیجه تست فشار'
        });
      }).catch(function () {});
    });

    var aiRun = $('#sp-ai-run');
    if (aiRun) aiRun.addEventListener('click', function () {
      var out = $('#sp-ai-out');
      post('speedpulse_ai_analyze', {}, {
        loading: 'در حال ارسال داده به هوش مصنوعی…',
        loadingTitle: 'تحلیل هوشمند',
        target: out,
        targetText: 'در انتظار پاسخ مدل…'
      }).then(function (res) {
        if (out) out.textContent = (res.data && (res.data.content || res.data.message)) || 'خطا';
        notify(res.success ? 'تحلیل هوش مصنوعی آماده شد.' : ((res.data && res.data.message) || 'خطا'), {
          type: res.success ? 'success' : 'error',
          title: 'هوش مصنوعی'
        });
      }).catch(function () {});
    });

    var patchGen = $('#sp-patch-gen');
    if (patchGen) patchGen.addEventListener('click', function () {
      post('speedpulse_patch_generate', {}, {
        loading: 'در حال تولید پچ بهینه‌ساز…',
        loadingTitle: 'تولید پچ'
      }).then(function (res) {
        var d = res.data || {};
        var code = $('#sp-patch-code');
        var diff = $('#sp-patch-diff');
        if (code) code.value = d.code || '';
        if (diff) diff.textContent = d.diff || d.message || '';
        notify(d.message || (res.success ? 'پیش‌نویس پچ آماده شد.' : 'تولید پچ ناموفق بود.'), {
          type: res.success ? 'success' : 'error',
          title: 'پچ بهینه‌ساز'
        });
      }).catch(function () {});
    });

    var patchSave = $('#sp-patch-save');
    if (patchSave) patchSave.addEventListener('click', function () {
      var code = ($('#sp-patch-code') || {}).value || '';
      post('speedpulse_patch_save', { code: code }, {
        loading: 'در حال ذخیره پیش‌نویس پچ…',
        loadingTitle: 'ذخیره پچ'
      }).then(function (res) {
        notify((res.data && res.data.message) || '', {
          type: res.success ? 'success' : 'error'
        });
      }).catch(function () {});
    });

    var canary = $('#sp-patch-canary');
    if (canary) canary.addEventListener('click', function () {
      post('speedpulse_patch_canary', {}, {
        loading: 'در حال اجرای آزمایش Canary…',
        loadingTitle: 'Canary امن'
      }).then(function (res) {
        notify((res.data && res.data.message) || 'نتیجه Canary', {
          type: res.success ? 'success' : 'error',
          title: 'نتیجه Canary',
          autoClose: 6000
        });
      }).catch(function () {});
    });

    var rollback = $('#sp-patch-rollback');
    if (rollback) rollback.addEventListener('click', function () {
      post('speedpulse_patch_rollback', {}, {
        loading: 'در حال بازگشت امن پچ…',
        loadingTitle: 'Rollback'
      }).then(function (res) {
        notify((res.data && res.data.message) || '', {
          type: res.success ? 'success' : 'error',
          title: 'بازگشت امن'
        });
      }).catch(function () {});
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
        post('speedpulse_save_settings', payload, {
          loading: 'در حال ذخیره تنظیمات…',
          loadingTitle: 'تنظیمات'
        }).then(function (res) {
          var msg = $('#speedpulse-settings-msg');
          if (msg) msg.textContent = (res.data && res.data.message) || '';
          notify((res.data && res.data.message) || 'تنظیمات ذخیره شد.', {
            type: res.success ? 'success' : 'error'
          });
        }).catch(function () {});
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
