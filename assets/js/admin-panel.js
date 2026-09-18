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
        '<p id="sp-modal-body" class="sp-modal__body"></p>' +
        '<button type="button" class="sp-modal__btn" data-sp-modal-close>متوجه شدم</button>' +
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

    modal.className = 'sp-modal is-open sp-modal--' + type;
    if (icon) icon.textContent = icons[type] || 'ℹ';
    if (ttl) ttl.textContent = title;
    if (body) body.textContent = message || '';
    modal.setAttribute('aria-hidden', 'false');

    if (modalTimer) clearTimeout(modalTimer);
    if (opts.autoClose !== false) {
      var ms = typeof opts.autoClose === 'number' ? opts.autoClose : 4200;
      modalTimer = setTimeout(hideModal, ms);
    }
  }

  function hideModal() {
    var modal = $('#speedpulse-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (modalTimer) {
      clearTimeout(modalTimer);
      modalTimer = null;
    }
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
      return '<span style="width:' + w + '%;background:' + (colors[i.key] || '#3dba9c') + '" title="' + esc(i.label) + '"></span>';
    }).join('');

    var rows = items.map(function (i) {
      var count = i.count ? (' — ' + i.count + ' مورد') : '';
      return '<div class="sp-row sp-breakdown-row">' +
        '<div><strong>' + esc(i.label) + '</strong>' +
        '<div class="sp-meta">' + esc(i.note || '') + count + '</div>' +
        '<div class="sp-bar"><span style="width:' + Math.min(100, i.percent || 0) + '%;background:' + (colors[i.key] || '#3dba9c') + '"></span></div></div>' +
        '<div class="sp-breakdown-nums"><strong>' + esc(i.human) + '</strong>' +
        '<span class="sp-badge">' + esc(i.percent) + '٪</span></div></div>';
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
    renderBreakdown(snap);
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
      renderTimeline(snap);
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
                return '<div class="sp-row"><div class="sp-sql">' + esc(r.url) + '</div><strong>' + esc(r.ttfb_ms) + ' ms</strong></div>';
              }).join('') + '</div>';
          }
        }).catch(function () {});
      }
    });

    var refreshLog = $('#sp-refresh-log');
    if (refreshLog) refreshLog.addEventListener('click', loadErrors);
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
          '<div class="sp-row"><div>میانگین TTFB</div><strong>' + esc(d.avg_ttfb_ms) + ' ms</strong></div>' +
          '<div class="sp-row"><div>P95</div><strong>' + esc(d.p95_ttfb_ms) + ' ms</strong></div>' +
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
