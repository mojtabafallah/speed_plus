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
  var lastSystem = null;
  var lastLive = null;
  var lastErrorCount = null;
  var lastWooJunk = null;
  var liveTimer = null;
  var selectedBrowserType = null;
  var exportingImage = false;

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
    if (name === 'system') loadSystem();
    if (name === 'live') startLive();
    else stopLive();
    if (name === 'total') renderTotalSummary();

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

  function breakdownItem(key) {
    var items = (((lastSnap || {}).time_breakdown || {}).items) || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].key === key) return items[i];
    }
    return null;
  }

  function setTabMetric(key, text) {
    var el = document.querySelector('[data-tab-metric="' + key + '"]');
    if (el) el.textContent = text || '—';
  }

  function sumAttributionCpu(snap) {
    var total = 0;
    var attr = (snap || {}).attribution || {};
    Object.keys(attr).forEach(function (k) {
      total += parseFloat(attr[k].cpu) || 0;
    });
    return total;
  }

  function updateTabMetrics() {
    var snap = lastSnap || {};
    var browser = lastBrowser || {};
    var bd = snap.time_breakdown || {};
    var q = breakdownItem('queries');
    var n = breakdownItem('network');
    var h = breakdownItem('hooks');

    var timelineVal = browser.browser_wall_human || bd.total_human || snap.total_human || '—';
    setTabMetric('timeline', timelineVal);

    setTabMetric('tips', lastTips && lastTips.length ? (lastTips.length + ' مورد') : '—');

    if (lastSystem && lastSystem.memory) {
      setTabMetric('system', lastSystem.memory.usage_human || '—');
    } else if (snap.memory_mb) {
      setTabMetric('system', snap.memory_mb + ' MB');
    } else {
      setTabMetric('system', '—');
    }

    if (lastLive && lastLive.memory) {
      setTabMetric('live', (lastLive.memory.usage_human || '—') +
        (lastLive.memory.usage_percent != null ? (' · ' + lastLive.memory.usage_percent + '%') : ''));
    } else {
      setTabMetric('live', '—');
    }

    if (q) {
      setTabMetric('queries', q.human + (q.count ? (' · ' + q.count) : ''));
    } else {
      setTabMetric('queries', snap.query_count ? (snap.query_count + ' کوئری') : '—');
    }

    var srcCpu = sumAttributionCpu(snap);
    if (srcCpu > 0) {
      setTabMetric('sources', fmtTime(srcCpu));
    } else if (n) {
      setTabMetric('sources', n.human);
    } else {
      setTabMetric('sources', '—');
    }

    setTabMetric('errors', lastErrorCount != null ? (lastErrorCount + ' خطا') : '—');
    setTabMetric('woo', lastWooJunk != null ? (lastWooJunk + ' مورد') : '—');
    setTabMetric('dom', lastDom && lastDom.elements != null ? (lastDom.elements + ' المان') : '—');
    setTabMetric('tools', 'ابزار');
    setTabMetric('ai', 'AI');

    var totalVal = browser.browser_wall_human || bd.total_human || snap.total_human || '—';
    setTabMetric('total', totalVal);

    var totalPanel = $('#sp-total');
    if (totalPanel && document.querySelector('.speedpulse-tab[data-panel="total"].is-active')) {
      renderTotalSummary();
    }
  }

  function renderTotalSummary() {
    var el = $('#sp-total');
    if (!el) return;
    var snap = lastSnap || {};
    var browser = lastBrowser || {};
    var bd = snap.time_breakdown || {};
    var q = breakdownItem('queries');
    var n = breakdownItem('network');
    var h = breakdownItem('hooks');
    var o = breakdownItem('other');
    var c = breakdownItem('cron');
    var srcCpu = sumAttributionCpu(snap);
    var mem = (lastLive && lastLive.memory) || (lastSystem && lastSystem.memory) || {};
    var cpu = (lastLive && lastLive.cpu) || (lastSystem && lastSystem.cpu) || {};

    var rows = [
      { label: 'زمان واقعی مرورگر (Network)', value: browser.browser_wall_human || '—', note: 'تجربه کاربر' },
      { label: 'زمان سرور HTML', value: bd.total_human || snap.total_human || '—', note: 'ساخت صفحه در PHP' },
      { label: 'جمع کوئری‌ها', value: q ? (q.human + (q.count ? ' (' + q.count + ' عدد)' : '')) : '—', note: 'مجموع زمان SQL' },
      { label: 'شبکه مسدودکننده', value: n ? (n.human + (n.count ? ' (' + n.count + ')' : '')) : '—', note: 'wp_remote_*' },
      { label: 'هوک‌های کلیدی', value: h ? h.human : '—', note: 'اندازه‌گیری‌شده' },
      { label: 'سایر پردازش PHP', value: o ? o.human : '—', note: 'باقی‌مانده' },
      { label: 'کرون / Action Scheduler', value: c ? c.human : '—', note: c && c.count ? (c.count + ' رویداد') : '' },
      { label: 'سهم منابع (CPU نسبت‌شده)', value: srcCpu ? fmtTime(srcCpu) : '—', note: Object.keys(snap.attribution || {}).length + ' منبع' },
      { label: 'رم درگیر', value: mem.usage_human || (snap.memory_mb ? snap.memory_mb + ' MB' : '—'), note: mem.usage_percent != null ? (mem.usage_percent + '% سقف') : '' },
      { label: 'Load CPU', value: cpu.load_1 != null ? String(cpu.load_1) : '—', note: 'میانگین ۱ دقیقه' },
      { label: 'راهکارهای پیشنهادی', value: lastTips && lastTips.length ? (lastTips.length + ' مورد') : '۰', note: '' },
      { label: 'خطاهای لاگ', value: lastErrorCount != null ? String(lastErrorCount) : '—', note: '' },
      { label: 'زباله‌های ووکامرس', value: lastWooJunk != null ? String(lastWooJunk) : '—', note: 'ترنزینت/نشست/متا' },
      { label: 'المان‌های DOM', value: lastDom && lastDom.elements != null ? String(lastDom.elements) : '—', note: lastDom && lastDom.depth != null ? ('عمق ' + lastDom.depth) : '' },
      { label: 'منابع Network مرورگر', value: browser.resource_count != null ? String(browser.resource_count) : '—', note: 'Heartbeat ' + (browser.heartbeat_count || 0) }
    ];

    el.innerHTML =
      '<div class="sp-total-hero">' +
        '<div class="sp-total-hero__label">جمع کل نمایشی</div>' +
        '<div class="sp-total-hero__value">' + esc(browser.browser_wall_human || bd.total_human || snap.total_human || '—') + '</div>' +
        '<p class="sp-meta">مرورگر (در صورت موجود) اولویت دارد؛ در غیر این صورت زمان سرور HTML.</p>' +
      '</div>' +
      '<div class="sp-list">' + rows.map(function (r) {
        return '<div class="sp-row"><div><strong>' + esc(r.label) + '</strong>' +
          (r.note ? '<div class="sp-meta">' + esc(r.note) + '</div>' : '') +
          '</div><strong>' + esc(r.value) + '</strong></div>';
      }).join('') + '</div>';
  }

  function exportPanelImage(panelName) {
    if (exportingImage) return;
    var panel = document.querySelector('.speedpulse-tab[data-panel="' + panelName + '"]');
    if (!panel) {
      notify('بخش مورد نظر پیدا نشد.', { type: 'error' });
      return;
    }
    if (typeof window.html2canvas !== 'function') {
      notify('موتور خروجی عکس بارگذاری نشده است. صفحه را تازه‌سازی کنید.', { type: 'error' });
      return;
    }

    exportingImage = true;
    showLoader('در حال ساخت تصویر از این بخش…', 'خروجی عکس');
    var hidden = [];
    $all('.sp-no-capture', panel).forEach(function (node) {
      hidden.push({ el: node, display: node.style.display });
      node.style.display = 'none';
    });

    var wasHidden = !panel.classList.contains('is-active');
    if (wasHidden) {
      panel.classList.add('is-active');
      panel.style.display = 'block';
    }

    window.html2canvas(panel, {
      backgroundColor: '#0f1419',
      scale: Math.min(2, window.devicePixelRatio || 1.5),
      useCORS: true,
      logging: false,
      scrollX: 0,
      scrollY: -window.scrollY
    }).then(function (canvas) {
      hidden.forEach(function (h) { h.el.style.display = h.display; });
      if (wasHidden) {
        panel.classList.remove('is-active');
        panel.style.display = '';
      }
      var link = document.createElement('a');
      link.download = 'speedpulse-' + panelName + '-' + Date.now() + '.png';
      link.href = canvas.toDataURL('image/png');
      link.click();
      notify('عکس بخش «' + panelName + '» دانلود شد.', { type: 'success', title: 'خروجی عکس' });
    }).catch(function (err) {
      hidden.forEach(function (h) { h.el.style.display = h.display; });
      if (wasHidden) {
        panel.classList.remove('is-active');
        panel.style.display = '';
      }
      notify((err && err.message) || 'ساخت تصویر ناموفق بود.', { type: 'error' });
    }).finally(function () {
      exportingImage = false;
      hideLoader();
    });
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
        if (!options.silent) {
          notify((err && err.message) ? err.message : 'ارتباط با سرور برقرار نشد.', {
            type: 'error',
            title: 'خطای ارتباط'
          });
        }
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
    stopLive();
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
      var detailEmpty = $('#sp-browser-detail');
      if (detailEmpty) {
        detailEmpty.hidden = true;
        detailEmpty.innerHTML = '';
      }
      return;
    }

    var doc = browser.server_document || {};
    var types = (browser.by_type || []).map(function (t) {
      var badge = '';
      if (t.key === 'heartbeat') badge = '<span class="sp-badge">Heartbeat</span> ';
      if (t.key === 'ajax') badge = '<span class="sp-badge warn">AJAX</span> ';
      if (t.key === 'rest') badge = '<span class="sp-badge danger">REST</span> ';
      var active = selectedBrowserType === t.key ? ' is-active' : '';
      return '<button type="button" class="sp-row sp-clickable sp-browser-type' + active + '" data-browser-type="' + esc(t.key) + '">' +
        '<div><strong>' + badge + esc(t.label) + '</strong>' +
        '<div class="sp-meta">' + esc(t.count) + ' درخواست — جمع نسبی ' + esc(t.human_sum) + '</div>' +
        '<div class="sp-click-hint">کلیک برای دیدن همه درخواست‌ها با شروع / پایان / منبع</div></div>' +
        '<div class="sp-breakdown-nums"><strong>' + esc(t.human_max || fmtTime(t.max_ms)) + '</strong>' +
        '<span class="sp-badge warn">کندترین</span></div></button>';
    }).join('');

    var metaLine = 'تعداد منابع: ' + esc(browser.resource_count || 0);
    if (browser.heartbeat_count != null || browser.ajax_count != null || browser.rest_count != null) {
      metaLine +=
        ' | Heartbeat: ' + esc(browser.heartbeat_count || 0) +
        ' | AJAX: ' + esc(browser.ajax_count || 0) +
        ' | REST: ' + esc(browser.rest_count || 0);
    }

    var slow = (browser.slowest || []).slice(0, 12).map(function (s) {
      var typeBadge = s.type === 'heartbeat'
        ? '<span class="sp-badge">Heartbeat</span> '
        : (s.type === 'ajax'
          ? '<span class="sp-badge warn">AJAX</span> '
          : (s.type === 'rest' ? '<span class="sp-badge danger">REST</span> ' : ''));
      return '<button type="button" class="sp-row sp-clickable" data-browser-res="' + esc(s.url || s.name || '') + '">' +
        '<div><div class="sp-sql">' + typeBadge + esc(s.name) + '</div>' +
        '<div class="sp-meta">' + esc(s.type) + (s.action ? (' | action=' + esc(s.action)) : '') +
        ' | شروع ' + esc(s.start_ms || 0) + 'ms → پایان ' + esc(s.end_ms || ((s.start_ms || 0) + (s.duration_ms || 0))) + 'ms' +
        (s.waiting_ms ? (' | انتظار سرور: ' + fmtTime(s.waiting_ms)) : '') + '</div></div>' +
        '<strong>' + esc(s.human || fmtTime(s.duration_ms)) + '</strong></button>';
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
        '<div class="sp-meta">' + metaLine + '</div>' +
      '</div>' +
      '<h4>دسته‌بندی منابع مرورگر <span class="sp-meta">(کلیک کنید)</span></h4><div class="sp-list">' + (types || '<p class="sp-meta">موردی نیست</p>') + '</div>' +
      '<h4>کندترین درخواست‌های Network</h4><div class="sp-list">' + (slow || '<p class="sp-meta">موردی نیست</p>') + '</div>' +
      (lastTips && lastTips.length
        ? '<div class="sp-browser-tips-link"><button type="button" class="button button-primary" data-tab-jump="tips">مشاهده راهکارهای پیشنهادی (' + lastTips.length + ')</button></div>'
        : '');

    if (selectedBrowserType) {
      renderBrowserTypeDetail(selectedBrowserType, false);
    }
  }

  function getBrowserResources(browser) {
    browser = browser || lastBrowser || {};
    if (browser.resources && browser.resources.length) return browser.resources;
    return browser.slowest || [];
  }

  function renderBrowserTypeDetail(typeKey, openModal) {
    var browser = lastBrowser || {};
    var resources = getBrowserResources(browser).filter(function (r) {
      return (r.type || '') === typeKey;
    });
    var typeMeta = null;
    (browser.by_type || []).forEach(function (t) {
      if (t.key === typeKey) typeMeta = t;
    });
    var label = typeMeta ? typeMeta.label : typeKey;
    selectedBrowserType = typeKey;

    var rows = resources.slice().sort(function (a, b) {
      return (b.duration_ms || 0) - (a.duration_ms || 0);
    }).map(function (r, idx) {
      var start = r.start_ms || 0;
      var end = r.end_ms != null ? r.end_ms : (start + (r.duration_ms || 0));
      var source = r.initiator || r.type || '—';
      return '<div class="sp-res-card">' +
        '<div class="sp-res-card__head"><strong>#' + (idx + 1) + ' ' + esc(r.name || 'درخواست') + '</strong>' +
        '<span class="sp-badge">' + esc(r.human || fmtTime(r.duration_ms)) + '</span></div>' +
        '<div class="sp-res-grid">' +
          '<div><b>' + esc(start) + ' ms</b><span>شروع</span></div>' +
          '<div><b>' + esc(end) + ' ms</b><span>پایان</span></div>' +
          '<div><b>' + esc(r.human || fmtTime(r.duration_ms)) + '</b><span>مدت</span></div>' +
          '<div><b>' + esc(source) + '</b><span>منبع (initiator)</span></div>' +
        '</div>' +
        (r.action ? '<div class="sp-meta">اکشن: <code dir="ltr">' + esc(r.action) + '</code></div>' : '') +
        (r.waiting_ms ? '<div class="sp-meta">انتظار سرور: ' + esc(fmtTime(r.waiting_ms)) + '</div>' : '') +
        (r.transfer_kb ? '<div class="sp-meta">حجم انتقال: ' + esc(r.transfer_kb) + ' KB</div>' : '') +
        '<div class="sp-sql" title="' + esc(r.url || '') + '">' + esc(r.url || r.name || '') + '</div>' +
      '</div>';
    }).join('');

    var panel = $('#sp-browser-detail');
    if (panel) {
      panel.hidden = false;
      panel.innerHTML =
        '<div class="sp-browser-detail__head">' +
          '<h4>جزئیات دسته: ' + esc(label) + ' (' + resources.length + ' درخواست)</h4>' +
          '<button type="button" class="button" data-browser-detail-close>بستن جزئیات</button>' +
        '</div>' +
        (rows || '<p class="sp-meta">درخواستی در این دسته نیست.</p>');
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // highlight active type buttons
    $all('.sp-browser-type').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-browser-type') === typeKey);
    });

    if (openModal) {
      var modalBody =
        '<p class="sp-meta">شروع و پایان بر اساس performance.now از لود صفحه هستند.</p>' +
        '<div class="sp-detail-stats">' +
          '<div><b>' + esc(resources.length) + '</b><span>تعداد</span></div>' +
          '<div><b>' + esc(typeMeta ? typeMeta.human_sum : '—') + '</b><span>جمع نسبی</span></div>' +
          '<div><b>' + esc(typeMeta ? (typeMeta.human_max || fmtTime(typeMeta.max_ms)) : '—') + '</b><span>کندترین</span></div>' +
        '</div>' +
        '<div class="sp-modal-scroll">' + (rows || '<p class="sp-meta">موردی نیست</p>') + '</div>';
      notify(modalBody, {
        type: 'info',
        title: 'درخواست‌های «' + label + '»',
        html: true,
        autoClose: false
      });
    }
  }

  function openBrowserResourceDetail(urlOrName) {
    var resources = getBrowserResources();
    var hit = null;
    resources.forEach(function (r) {
      if (!hit && ((r.url && r.url === urlOrName) || (r.name && r.name === urlOrName))) {
        hit = r;
      }
    });
    if (!hit) return;
    var start = hit.start_ms || 0;
    var end = hit.end_ms != null ? hit.end_ms : (start + (hit.duration_ms || 0));
    var html =
      '<div class="sp-detail-stats">' +
        '<div><b>' + esc(start) + ' ms</b><span>شروع</span></div>' +
        '<div><b>' + esc(end) + ' ms</b><span>پایان</span></div>' +
        '<div><b>' + esc(hit.human || fmtTime(hit.duration_ms)) + '</b><span>مدت</span></div>' +
      '</div>' +
      '<p><strong>نوع:</strong> ' + esc(hit.type || '—') + '</p>' +
      '<p><strong>منبع:</strong> ' + esc(hit.initiator || '—') + '</p>' +
      (hit.action ? '<p><strong>اکشن:</strong> <code dir="ltr">' + esc(hit.action) + '</code></p>' : '') +
      (hit.waiting_ms ? '<p><strong>انتظار سرور:</strong> ' + esc(fmtTime(hit.waiting_ms)) + '</p>' : '') +
      (hit.transfer_kb ? '<p><strong>حجم:</strong> ' + esc(hit.transfer_kb) + ' KB</p>' : '') +
      '<p class="sp-sql" dir="ltr">' + esc(hit.url || hit.name || '') + '</p>';
    notify(html, {
      type: 'info',
      title: hit.name || 'جزئیات درخواست',
      html: true,
      autoClose: false
    });
  }

  function loadSystem() {
    var el = $('#sp-system');
    post('speedpulse_system_info', {}, {
      loading: 'در حال خواندن اطلاعات سرور و وردپرس…',
      loadingTitle: 'اطلاعات سیستم',
      target: el,
      targetText: 'در حال جمع‌آوری مشخصات سیستم…'
    }).then(function (res) {
      if (!res || !res.success) {
        notify((res && res.data && res.data.message) || 'خواندن سیستم ناموفق بود.', { type: 'error' });
        return;
      }
      lastSystem = res.data.system || {};
      renderSystem(lastSystem);
      updateTabMetrics();
    }).catch(function () {});
  }

  function yn(v) {
    return v ? 'بله' : 'خیر';
  }

  function renderSystem(sys) {
    var el = $('#sp-system');
    if (!el || !sys) return;
    var wp = sys.wordpress || {};
    var php = sys.php || {};
    var mem = sys.memory || {};
    var cpu = sys.cpu || {};
    var srv = sys.server || {};
    var db = sys.database || {};
    var theme = sys.theme || {};
    var plugins = sys.plugins || {};
    var woo = sys.woocommerce || {};
    var cache = sys.cache || {};
    var opc = cache.opcache || {};
    var ext = php.extensions || {};

    var pluginRows = (plugins.active_list || []).map(function (p) {
      return '<div class="sp-row"><div><strong>' + esc(p.name) + '</strong>' +
        '<div class="sp-meta" dir="ltr">' + esc(p.file) + '</div></div>' +
        '<span class="sp-badge">' + esc(p.version || '—') + '</span></div>';
    }).join('');

    el.innerHTML =
      '<div class="sp-sys-grid">' +
        '<div class="sp-sys-card"><h4>وردپرس</h4>' +
          '<div class="sp-sys-kv"><span>نسخه</span><b>' + esc(wp.version) + '</b></div>' +
          '<div class="sp-sys-kv"><span>چندسایته</span><b>' + yn(wp.multisite) + '</b></div>' +
          '<div class="sp-sys-kv"><span>WP_DEBUG</span><b>' + yn(wp.debug) + '</b></div>' +
          '<div class="sp-sys-kv"><span>زبان</span><b>' + esc(wp.locale) + '</b></div>' +
          '<div class="sp-sys-kv"><span>آدرس</span><b dir="ltr">' + esc(wp.home) + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>PHP</h4>' +
          '<div class="sp-sys-kv"><span>نسخه</span><b>' + esc(php.version) + '</b></div>' +
          '<div class="sp-sys-kv"><span>SAPI</span><b>' + esc(php.sapi) + '</b></div>' +
          '<div class="sp-sys-kv"><span>OS</span><b>' + esc(php.os) + '</b></div>' +
          '<div class="sp-sys-kv"><span>سقف حافظه</span><b>' + esc(php.memory_limit) + '</b></div>' +
          '<div class="sp-sys-kv"><span>max_execution</span><b>' + esc(php.max_execution) + 's</b></div>' +
          '<div class="sp-sys-kv"><span>upload / post</span><b>' + esc(php.upload_max) + ' / ' + esc(php.post_max) + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>حافظه فعلی</h4>' +
          '<div class="sp-gauge"><span style="width:' + Math.min(100, mem.usage_percent || 0) + '%"></span></div>' +
          '<div class="sp-sys-kv"><span>مصرف</span><b>' + esc(mem.usage_human) + ' (' + esc(mem.usage_percent) + '%)</b></div>' +
          '<div class="sp-sys-kv"><span>اوج</span><b>' + esc(mem.peak_human) + ' (' + esc(mem.peak_percent) + '%)</b></div>' +
          '<div class="sp-sys-kv"><span>سقف</span><b>' + esc(mem.limit_human) + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>CPU</h4>' +
          '<div class="sp-sys-kv"><span>هسته‌ها</span><b>' + esc(cpu.cores || '—') + '</b></div>' +
          '<div class="sp-sys-kv"><span>Load 1/5/15</span><b>' + esc(cpu.load_1 != null ? cpu.load_1 : '—') + ' / ' +
            esc(cpu.load_5 != null ? cpu.load_5 : '—') + ' / ' + esc(cpu.load_15 != null ? cpu.load_15 : '—') + '</b></div>' +
          '<div class="sp-sys-kv"><span>user / sys</span><b>' + esc(cpu.user_time_ms != null ? cpu.user_time_ms + ' ms' : '—') +
            ' / ' + esc(cpu.sys_time_ms != null ? cpu.sys_time_ms + ' ms' : '—') + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>سرور و دیسک</h4>' +
          '<div class="sp-sys-kv"><span>نرم‌افزار</span><b>' + esc(srv.software || '—') + '</b></div>' +
          '<div class="sp-sys-kv"><span>HTTPS</span><b>' + yn(srv.https) + '</b></div>' +
          '<div class="sp-sys-kv"><span>دیسک آزاد</span><b>' + esc(srv.disk_free) + ' / ' + esc(srv.disk_total) + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>دیتابیس</h4>' +
          '<div class="sp-sys-kv"><span>نسخه</span><b>' + esc(db.version) + '</b></div>' +
          '<div class="sp-sys-kv"><span>حجم</span><b>' + esc(db.size) + '</b></div>' +
          '<div class="sp-sys-kv"><span>جداول</span><b>' + esc(db.tables) + '</b></div>' +
          '<div class="sp-sys-kv"><span>charset</span><b>' + esc(db.charset) + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>قالب و ووکامرس</h4>' +
          '<div class="sp-sys-kv"><span>قالب</span><b>' + esc(theme.name) + ' ' + esc(theme.version) + '</b></div>' +
          '<div class="sp-sys-kv"><span>والد</span><b>' + esc(theme.parent || '—') + '</b></div>' +
          '<div class="sp-sys-kv"><span>ووکامرس</span><b>' + (woo.active ? esc(woo.version || 'فعال') : 'غیرفعال') + '</b></div>' +
        '</div>' +
        '<div class="sp-sys-card"><h4>افزونه‌ها و کش</h4>' +
          '<div class="sp-sys-kv"><span>فعال / کل</span><b>' + esc(plugins.active) + ' / ' + esc(plugins.total) + '</b></div>' +
          '<div class="sp-sys-kv"><span>Object Cache</span><b>' + yn(cache.object_cache) + '</b></div>' +
          '<div class="sp-sys-kv"><span>OPcache</span><b>' + yn(opc.enabled) + '</b></div>' +
          '<div class="sp-sys-kv"><span>curl / gd / redis</span><b>' + yn(ext.curl) + ' / ' + yn(ext.gd) + ' / ' + yn(ext.redis) + '</b></div>' +
        '</div>' +
      '</div>' +
      '<h4>افزونه‌های فعال</h4><div class="sp-list">' + (pluginRows || '<p class="sp-meta">—</p>') + '</div>' +
      '<p class="sp-meta">تولید شده: ' + esc(sys.generated_at || '') + '</p>';
  }

  function startLive() {
    stopLive();
    var status = $('#sp-live-status');
    if (status) {
      status.textContent = 'زنده';
      status.className = 'sp-badge ok';
    }
    pollLive();
    liveTimer = setInterval(pollLive, 2000);
  }

  function stopLive() {
    if (liveTimer) {
      clearInterval(liveTimer);
      liveTimer = null;
    }
    var status = $('#sp-live-status');
    if (status) {
      status.textContent = 'متوقف';
      status.className = 'sp-badge';
    }
  }

  function pollLive() {
    post('speedpulse_live_metrics', {}, { loading: false, silent: true }).then(function (res) {
      if (!res || !res.success) return;
      renderLive(res.data.live || {});
    }).catch(function () {});
  }

  function renderLive(live) {
    var el = $('#sp-live');
    var clock = $('#sp-live-clock');
    lastLive = live || null;
    updateTabMetrics();
    if (clock) clock.textContent = 'آخرین نمونه: ' + ((live && live.at_human) || '—');
    if (!el) return;
    var mem = (live && live.memory) || {};
    var cpu = (live && live.cpu) || {};
    var parts = (mem.parts || []).map(function (p) {
      return '<div class="sp-row"><div><strong>' + esc(p.label) + '</strong>' +
        '<div class="sp-meta">' + esc(p.note || '') + '</div></div><strong>' + esc(p.human) + '</strong></div>';
    }).join('');
    var tops = (live.top_sources || []).map(function (s) {
      return '<div class="sp-row"><div><strong>' + esc(s.name) + '</strong>' +
        '<div class="sp-meta">کوئری: ' + esc(s.queries) + ' | هوک: ' + esc(s.hooks) + '</div></div>' +
        '<strong>' + esc(fmtTime(s.cpu_ms)) + '</strong></div>';
    }).join('');
    var loadPct = 0;
    if (cpu.load_1 != null && cpu.cores) {
      loadPct = Math.min(100, Math.round((cpu.load_1 / Math.max(1, cpu.cores)) * 100));
    }

    el.innerHTML =
      '<div class="sp-live-grid">' +
        '<div class="sp-live-card">' +
          '<div class="sp-live-card__label">رم درگیر (real)</div>' +
          '<div class="sp-live-card__value">' + esc(mem.usage_human || '—') + '</div>' +
          '<div class="sp-gauge sp-gauge--lg"><span style="width:' + Math.min(100, mem.usage_percent || 0) + '%"></span></div>' +
          '<div class="sp-meta">' + esc(mem.usage_percent || 0) + '% از سقف ' + esc(mem.limit_human || '—') +
            ' — اوج: ' + esc(mem.peak_human || '—') + '</div>' +
        '</div>' +
        '<div class="sp-live-card">' +
          '<div class="sp-live-card__label">CPU / Load</div>' +
          '<div class="sp-live-card__value">' + esc(cpu.load_1 != null ? cpu.load_1 : '—') + '</div>' +
          '<div class="sp-gauge sp-gauge--lg sp-gauge--cpu"><span style="width:' + loadPct + '%"></span></div>' +
          '<div class="sp-meta">Load ۱/۵/۱۵: ' + esc(cpu.load_1 != null ? cpu.load_1 : '—') + ' / ' +
            esc(cpu.load_5 != null ? cpu.load_5 : '—') + ' / ' + esc(cpu.load_15 != null ? cpu.load_15 : '—') +
            (cpu.cores ? (' | هسته: ' + esc(cpu.cores)) : '') + '</div>' +
          '<div class="sp-meta">user: ' + esc(cpu.user_time_ms != null ? cpu.user_time_ms + ' ms' : '—') +
            ' | sys: ' + esc(cpu.sys_time_ms != null ? cpu.sys_time_ms + ' ms' : '—') + '</div>' +
          '<p class="sp-meta">' + esc(cpu.note || '') + '</p>' +
        '</div>' +
      '</div>' +
      '<h4>جزئیات داخل رم</h4><div class="sp-list">' + (parts || '<p class="sp-meta">—</p>') + '</div>' +
      '<h4>منابع درگیر (آخرین اسنپ‌شات)</h4><div class="sp-list">' + (tops || '<p class="sp-meta">هنوز اسنپ‌شاتی نیست؛ ضبط را روشن کنید.</p>') + '</div>' +
      '<p class="sp-meta" dir="ltr">' + esc((live.request && live.request.method) || '') + ' ' +
        esc((live.request && live.request.uri) || '') + '</p>';
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
      updateTabMetrics();
      renderTotalSummary();
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
      var errTotal = 0;
      Object.keys(cats).forEach(function (name) {
        errTotal += parseInt(cats[name].count, 10) || 0;
      });
      lastErrorCount = errTotal;
      updateTabMetrics();
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
        lastWooJunk = 0;
        updateTabMetrics();
        el.innerHTML = '<p class="sp-meta">' + esc(d.message) + '</p>';
        return;
      }
      lastWooJunk = (parseInt(d.expired_transients, 10) || 0) +
        (parseInt(d.expired_sessions, 10) || 0) +
        (parseInt(d.orphan_postmeta, 10) || 0);
      updateTabMetrics();
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
    updateTabMetrics();

    window.addEventListener('speedpulse:browser-metrics', function (ev) {
      if (ev && ev.detail) {
        lastBrowser = ev.detail;
        D.browserMetrics = ev.detail;
        if (drawer && drawer.classList.contains('is-open')) {
          renderBrowser(lastBrowser);
        }
        updateTabMetrics();
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
      var tabBtn = t.closest ? t.closest('.speedpulse-tabs button[data-tab]') : null;
      if (tabBtn) {
        switchTab(tabBtn.getAttribute('data-tab'));
        return;
      }

      var exportImgBtn = t.closest ? t.closest('[data-export-panel]') : null;
      if (exportImgBtn) {
        e.preventDefault();
        exportPanelImage(exportImgBtn.getAttribute('data-export-panel'));
        return;
      }

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
      var browserTypeEl = t.closest ? t.closest('[data-browser-type]') : null;
      if (browserTypeEl) {
        e.preventDefault();
        renderBrowserTypeDetail(browserTypeEl.getAttribute('data-browser-type'), true);
        return;
      }
      var browserResEl = t.closest ? t.closest('[data-browser-res]') : null;
      if (browserResEl) {
        e.preventDefault();
        openBrowserResourceDetail(browserResEl.getAttribute('data-browser-res'));
        return;
      }
      if (t.matches && t.matches('[data-browser-detail-close]')) {
        e.preventDefault();
        selectedBrowserType = null;
        var det = $('#sp-browser-detail');
        if (det) {
          det.hidden = true;
          det.innerHTML = '';
        }
        $all('.sp-browser-type').forEach(function (btn) { btn.classList.remove('is-active'); });
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
    var refreshSystem = $('#sp-refresh-system');
    if (refreshSystem) refreshSystem.addEventListener('click', loadSystem);
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
