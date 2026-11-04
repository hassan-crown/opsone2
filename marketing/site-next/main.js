/* OpsOne site-next home: the order's line, the screens it opens, the roles, the demo form.
   No library: CSS transitions and the Web Animations API, transform and opacity only.
   Everything is readable without this file; it only adds the switches and the motion. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isRTL = document.dir === 'rtl';
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';
  const fmt = (n) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const centre = (el) => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; };
  // run fn once, the first time el is at least `t` on screen
  const once = (el, fn, t = 0.4) => {
    if (!el) return;
    if (!('IntersectionObserver' in window)) { fn(); return; }
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); fn(); } }, { threshold: t });
    io.observe(el);
  };
  const count = (el, to, from = 0, ms = 1200) => {
    const t0 = performance.now();
    const tick = (now) => {
      const k = Math.min(1, (now - t0) / ms), e = 1 - Math.pow(1 - k, 3);
      el.textContent = fmt(from + (to - from) * e);
      if (k < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  };

  /* ---------- hero: one order on one line ---------- */
  // Every value comes from the approved copy and its demo order ORD-2026-00178.
  const screens = {
    showroom: ['فرصة البيع INT-0231', 'المعرض', [['عبدالله سعد، زيارة المعرض', 'فرع الرياض'], ['2 سبتمبر', 'سُجّل الاهتمام'], ['4 سبتمبر', 'طلب موعد قياس'], ['تحويل إلى طلب', ['done', 'ORD-2026-00178']]]],
    office: ['العقد والدفعة الأولى', 'العقد والدفعة', [['عرض سعر QUO-2026-00071', '48,300.00 ر.س'], ['العقد CON-2026-00031', ['done', 'موقّع 23 سبتمبر']], ['الدفعة الأولى 50%، RCV-2026-00412', ['done', 'مسدد']], ['الدفعة الأخيرة عند التركيب', ['next', '24,150.00']]]],
    factory: ['مراحل الطلب ORD-2026-00178', 'المصنع', [['العقد والدفعة الأولى', ['done', 'مكتملة']], ['إصدار أمر التصنيع، الإنتاج', ['done', 'مكتملة']], ['التصنيع، 6 نوفمبر', ['live', 'جارية']], ['التركيب والتسليم، 8 نوفمبر', ['next', 'لاحقًا']]]],
    dispatch: ['مواعيد اليوم', 'التسليم', [['08:00 تركيب، حي النرجس', 'زياد سالم'], ['12:00 قياس، حي الياسمين', 'حسن علي'], ['14:00 تسليم بنود جاهزة', 'عمر يوسف']]],
    site: ['تركيب في موقع العميل', 'موقع العميل', [['ORD-2026-00178، حي النرجس', '8 نوفمبر، 08:00'], ['صور قبل التركيب وبعده', 'من جوال الفني'], ['توقيع العميل على الاستلام', ['next', 'لاحقًا']]]],
  };
  const LIVE = 2;
  const route = document.getElementById('route');
  if (route) {
    const stationsEl = document.getElementById('stations');
    const stations = [...stationsEl.querySelectorAll('.station')];
    const fill = document.getElementById('fill'), ticket = document.getElementById('ticket');
    const screen = document.getElementById('screen'), body = document.getElementById('screen-body'), notch = document.getElementById('notch');
    const vertical = () => matchMedia('(max-width: 760px)').matches;
    const dotOf = (k) => stations.find((s) => s.dataset.k === k).querySelector('.dot');
    const cell = (v) => (Array.isArray(v) ? `<span class="pill-s pill-s--${v[0]}">${v[1]}</span>` : `<b class="nums">${v}</b>`);
    const render = (k) => { const [t, room, rows] = screens[k]; return `<div class="shead"><b class="nums">${t}</b><small>${room}</small></div><ul class="srows">${rows.map((r) => `<li><span class="nums">${r[0]}</span>${cell(r[1])}</li>`).join('')}</ul>`; };
    let current = 'factory', swapTimer, drawn = false;

    // The screen sits in the stations' grid: inline under its station on phones, a full-width second row on wide screens,
    // where it slides under the selected station. It starts under the factory in the HTML, so nothing moves on load.
    const place = (k) => {
      if (vertical()) {
        const st = stations.find((s) => s.dataset.k === k);
        if (screen.previousElementSibling !== st) st.after(screen);
        screen.style.setProperty('--x', '0px');
        return;
      }
      const box = route.getBoundingClientRect(), [dx] = centre(dotOf(k));
      const w = screen.offsetWidth, cx = dx - box.left;
      const fromStart = isRTL ? box.width - cx : cx; // translate is physical: measure from the inline start
      const start = Math.max(0, Math.min(box.width - w, fromStart - w / 2));
      screen.style.setProperty('--x', `${isRTL ? -start : start}px`);
      const n = fromStart - start - 7;
      notch.style.setProperty('--n', `${isRTL ? -n : n}px`);
      if (!isRTL) { notch.style.right = 'auto'; notch.style.left = '0'; }
    };
    // The fill ends exactly on the live station's centre, measured, so it holds when the phone screen opens inline.
    const fillTo = () => {
      if (!drawn) return;
      const track = fill.parentElement.getBoundingClientRect(), [lx, ly] = centre(dotOf('factory'));
      fill.style.transform = vertical()
        ? `scaleY(${((ly - track.top) / track.height).toFixed(4)})`
        : `scaleX(${((isRTL ? track.right - lx : lx - track.left) / track.width).toFixed(4)})`;
    };
    const select = (k, animate) => {
      current = k;
      stations.forEach((s) => s.setAttribute('aria-pressed', String(s.dataset.k === k)));
      place(k);
      fillTo();
      clearTimeout(swapTimer);
      if (!animate) { body.innerHTML = render(k); return; }
      body.classList.add('is-leaving');
      swapTimer = setTimeout(() => { body.innerHTML = render(k); body.classList.remove('is-leaving'); }, reduce ? 0 : 160);
    };
    stations.forEach((s) => s.addEventListener('click', () => { if (s.dataset.k !== current) select(s.dataset.k, true); }));
    const placeTicket = () => {
      const box = stationsEl.getBoundingClientRect(), [lx] = centre(dotOf('factory')), [sx] = centre(dotOf('showroom'));
      ticket.style.left = `${lx - box.left}px`;
      return sx - lx;
    };
    // The draw: the fill runs to the live station (1200ms, ease-in-out: movement on screen), each station lights as
    // the fill reaches it (the symmetric curve passes the middle at half time), and the order tag rides its head.
    const play = () => {
      const DRAW = reduce ? 0 : 1200;
      if (!vertical() && !reduce) {
        ticket.style.transition = 'none';
        ticket.style.setProperty('--dx', `${placeTicket()}px`);
        void ticket.offsetWidth;
        ticket.style.transition = '';
        ticket.style.setProperty('--dx', '0px');
      }
      ticket.classList.add('is-in');
      drawn = true;
      fillTo();
      stations.forEach((s, i) => {
        if (i > LIVE) return;
        setTimeout(() => s.classList.add(i === LIVE ? 'is-live' : 'is-done'), DRAW * (i / LIVE));
      });
    };
    select('factory', false);
    placeTicket();
    addEventListener('resize', () => { place(current); placeTicket(); fillTo(); });
    requestAnimationFrame(() => requestAnimationFrame(play));
  }

  /* ---------- the order in one window: manufacturing finishes, installation starts, the client hears on WhatsApp ---------- */
  const app = document.querySelector('.benefits .app');
  if (app && !reduce && app.animate) {
    const q = (s) => app.querySelector(s);
    const make = q('[data-stg="make"]'), install = q('[data-stg="install"]');
    const toast = document.querySelector('[data-toast]');
    const bars = [...app.querySelectorAll('.stg__bar i, [data-meter]')];
    toast.style.opacity = '0';
    once(app, () => {
      app.querySelectorAll('[data-count]').forEach((el, i) => count(el, +el.dataset.count, i === 2 ? 48300 : 0));
      bars.forEach((b) => { const to = getComputedStyle(b).transform; b.animate([{ transform: 'scaleX(0)' }, { transform: to }], { duration: 1200, easing: EASE_OUT, delay: 150 }); });
      const makeBar = make.querySelector('.stg__bar i');
      setTimeout(() => {
        makeBar.animate([{ transform: getComputedStyle(makeBar).transform }, { transform: 'scaleX(1)' }], { duration: 900, easing: EASE_IN_OUT, fill: 'forwards' });
      }, 2200);
      setTimeout(() => {
        q('[data-task]').classList.add('is-done');
        make.classList.replace('is-now', 'is-done');
        make.querySelector('.stg__dot').innerHTML = '<svg class="ic"><use href="#i-check"/></svg>';
        const c1 = make.querySelector('.chip'); c1.className = 'chip chip--ok'; c1.textContent = 'مكتملة';
        install.classList.add('is-now');
        const c2 = install.querySelector('.chip'); c2.className = 'chip chip--info'; c2.textContent = 'جارية';
        const st = q('[data-status]'); st.textContent = 'قيد التركيب';
        [c1, c2, st].forEach((el, i) => el.animate([{ opacity: 0, transform: 'scale(0.95)' }, { opacity: 1, transform: 'none' }], { duration: 240, delay: i * 50, easing: EASE_OUT, fill: 'backwards' }));
        install.querySelector('.stg__bar i').animate([{ transform: 'scaleX(0)' }, { transform: 'scaleX(0.18)' }], { duration: 700, easing: EASE_OUT, fill: 'forwards' });
        toast.style.opacity = '';
        toast.animate([{ opacity: 0, transform: 'translateY(16px)' }, { opacity: 1, transform: 'none' }], { duration: 420, delay: 300, easing: EASE_OUT, fill: 'backwards' });
      }, 3200);
    }, 0.45);
  }

  /* ---------- the lead becomes the order: «تحويل إلى طلب» is pressed once ---------- */
  const duo = document.querySelector('[data-duo]');
  if (duo && !reduce) {
    duo.classList.add('is-before');
    const press = duo.querySelector('[data-convert]');
    once(duo, () => {
      setTimeout(() => press.classList.add('is-pressed'), 900);
      setTimeout(() => {
        press.classList.remove('is-pressed');
        duo.classList.remove('is-before');
        duo.querySelectorAll('.duo__now, .duo__done').forEach((el) => el.animate && el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 300, easing: EASE_OUT }));
      }, 1150);
    }, 0.6);
  }

  /* ---------- journey: the same line, vertical; the step at the middle of the screen owns the stage ---------- */
  const list = document.getElementById('steps');
  if (list) {
    const steps = [...list.querySelectorAll('.jstep')];
    const custom = document.getElementById('path');
    const all = [...steps, custom];
    const stage = document.getElementById('stage'), sfill = document.getElementById('steps-fill');
    const figs = steps.map((s) => s.querySelector('.fig'));
    const wide = matchMedia('(min-width: 1024px)');
    const mount = () => figs.forEach((f, i) => (wide.matches ? stage.appendChild(f) : steps[i].appendChild(f)));
    mount();
    wide.addEventListener('change', mount);
    const replay = (step) => {
      if (reduce) return;
      const ask = step.querySelector('[data-approve]');
      const fig = figs[steps.indexOf(step)] || step;
      const askIn = (fig && fig.querySelector('[data-approve]')) || ask;
      if (askIn && !askIn.dataset.played) {
        askIn.dataset.played = '1';
        askIn.classList.add('is-pending');
        setTimeout(() => askIn.classList.remove('is-pending'), 1400);
      }
      const ink = fig && fig.querySelector('.sign__pad path');
      if (ink && !ink.dataset.played && ink.animate) {
        ink.dataset.played = '1';
        const len = ink.getTotalLength();
        ink.animate([{ strokeDasharray: len, strokeDashoffset: len }, { strokeDasharray: len, strokeDashoffset: 0 }], { duration: 1100, easing: EASE_IN_OUT, delay: 250, fill: 'backwards' });
      }
    };
    let active = -1, ticking = false;
    const update = () => {
      ticking = false;
      const mid = innerHeight * 0.5;
      let a = 0;
      all.forEach((s, i) => { if (s.getBoundingClientRect().top < mid) a = i; });
      if (a === active) return;
      active = a;
      all.forEach((s, i) => { s.classList.toggle('is-on', i === a); s.classList.toggle('is-past', i < a); });
      figs.forEach((f, i) => f.classList.toggle('is-on', i === Math.min(a, steps.length - 1)));
      const track = sfill.parentElement.getBoundingClientRect();
      const dot = (all[Math.min(a, steps.length - 1)]).querySelector('.jdot').getBoundingClientRect();
      sfill.style.setProperty('--p', a >= steps.length ? '1' : ((dot.top + dot.height / 2 - track.top) / track.height).toFixed(4));
      replay(all[a]);
    };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    addEventListener('resize', () => { active = -1; update(); });
    update();
  }

  /* ---------- item 9: the owner approves the draft, version 4 goes live, running orders stay on version 3 ---------- */
  const release = document.querySelector('[data-release]');
  if (release && !reduce) {
    once(release, () => {
      const press = release.querySelector('[data-publish]');
      setTimeout(() => press.classList.add('is-pressed'), 1400);
      setTimeout(() => {
        press.classList.remove('is-pressed');
        release.classList.add('is-published');
        release.querySelectorAll('.v-new').forEach((el) => el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 300, easing: EASE_OUT }));
      }, 1600);
    }, 0.5);
  }

  /* ---------- roles: the three stations switch the lines and the view ---------- */
  const tablist = document.querySelector('.roles__tabs');
  const films = [...document.querySelectorAll('[data-film-item]')];
  let filmInView = false;
  const playActive = () => films.forEach((f) => {
    const v = f.querySelector('video');
    if (!v) return;
    if (!f.hidden && filmInView && !reduce) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause();
  });
  const media = document.querySelector('.roles__media');
  if (media && 'IntersectionObserver' in window) new IntersectionObserver(([e]) => { filmInView = e.isIntersecting; playActive(); }, { threshold: 0.2 }).observe(media);
  if (tablist) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panel = document.getElementById('panel-roles');
    const pick = (tab, focus) => {
      tabs.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      const key = tab.dataset.film;
      panel.setAttribute('aria-labelledby', tab.id);
      panel.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
      films.forEach((f) => { f.hidden = f.dataset.filmItem !== key; });
      playActive();
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => pick(tab));
      tab.addEventListener('keydown', (e) => {
        // right to left: ArrowLeft moves forward
        const step = { ArrowLeft: isRTL ? 1 : -1, ArrowRight: isRTL ? -1 : 1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        pick(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
  }

  /* ---------- tools: six places on the order's line; wide screens show one screen at a time ---------- */
  const tools = document.querySelector('.tools');
  if (tools) {
    const tline = tools.querySelector('[data-tline]');
    const tabs = [...tline.querySelectorAll('.tstation')];
    const panes = [...tools.querySelectorAll('.tool')];
    const wideTools = matchMedia('(min-width: 761px)');
    let pickedTool = tabs[0].dataset.tool;
    const show = (key, focus) => {
      pickedTool = key;
      tabs.forEach((t) => { const on = t.dataset.tool === key; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; if (on && focus) t.focus(); });
      panes.forEach((p) => {
        p.classList.toggle('is-off', p.dataset.tool !== key);
        const v = p.querySelector('video');
        if (v) { if (p.dataset.tool === key && !reduce) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause(); }
      });
    };
    const setMode = () => {
      const on = wideTools.matches;
      tools.classList.toggle('is-tabbed', on);
      if (on) { tline.setAttribute('role', 'tablist'); tabs.forEach((t) => t.setAttribute('role', 'tab')); panes.forEach((p) => { p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', 'tt-' + p.dataset.tool); }); show(pickedTool); }
      else { tline.removeAttribute('role'); tabs.forEach((t) => { t.removeAttribute('role'); t.removeAttribute('aria-selected'); }); panes.forEach((p) => { p.removeAttribute('role'); p.removeAttribute('aria-labelledby'); p.classList.remove('is-off'); }); }
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => show(t.dataset.tool));
      t.addEventListener('keydown', (e) => {
        const step = { ArrowLeft: isRTL ? 1 : -1, ArrowRight: isRTL ? -1 : 1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        show(tabs[(i + step + tabs.length) % tabs.length].dataset.tool, true);
      });
    });
    setMode();
    wideTools.addEventListener('change', setMode);
  }

  /* ---------- tools: the client's messages arrive, the signature is drawn, the receipt counts ---------- */
  if (!reduce && document.body.animate) {
    const chat = document.querySelector('.chat');
    once(chat.closest('.tool__art'), () => chat.querySelectorAll('.ui-wa').forEach((m, i) => m.animate([{ opacity: 0, transform: 'translateY(10px)' }, { opacity: 1, transform: 'none' }], { duration: 360, delay: i * 260, easing: EASE_OUT, fill: 'backwards' })), 0.5);
    const sign = document.querySelector('.doc__sign svg path');
    if (sign) once(sign.closest('.doc'), () => { const len = sign.getTotalLength(); sign.animate([{ strokeDasharray: len, strokeDashoffset: len }, { strokeDasharray: len, strokeDashoffset: 0 }], { duration: 1200, easing: EASE_IN_OUT }); }, 0.6);
    document.querySelectorAll('.tools [data-count]').forEach((el) => once(el, () => count(el, +el.dataset.count), 0.8));
  }
  document.querySelectorAll('video[data-autoplay]').forEach((v) => {
    if (!('IntersectionObserver' in window)) return;
    new IntersectionObserver(([e]) => { if (e.isIntersecting && !reduce && !v.closest('.is-off')) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause(); }, { threshold: 0.3 }).observe(v);
  });

  /* ---------- footer credit: the developer's name links out only once its URL is set in config.js ---------- */
  const devUrl = ((window.OPSONE_LANDING || {}).developerUrl || '').trim();
  const dev = document.querySelector('[data-developer]');
  if (dev && /^https:\/\//.test(devUrl)) {
    const link = document.createElement('a');
    link.href = devUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = dev.textContent;
    dev.replaceWith(link);
  }

  /* ---------- demo request form (behaviour unchanged from marketing/landing/main.js) ---------- */
  const form = document.querySelector('[data-demo-form]');
  if (form) {
    const status = form.querySelector('[data-status]');
    const submit = form.querySelector('[type="submit"]');
    const label = submit.textContent;
    const latin = (s) => s.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
    const phoneOk = (v) => /^(?:\+?966|0)?5\d{8}$/.test(latin(v).replace(/[\s-]/g, ''));
    const rules = {
      name: (v) => (v.trim().length >= 2 ? '' : 'اكتب اسمك.'),
      company: (v) => (v.trim().length >= 2 ? '' : 'اكتب اسم المنشأة.'),
      phone: (v) => (!v.trim() ? 'اكتب رقم الجوال.' : phoneOk(v) ? '' : 'اكتب رقم جوال سعوديًا صحيحًا، مثل 0551234567.'),
      trade: (v) => (v ? '' : 'اختر ما تبيعه.'),
    };
    const check = (field) => {
      const rule = rules[field.name];
      if (!rule) return true;
      const msg = rule(field.value);
      const err = document.getElementById(`${field.id}-err`);
      field.setAttribute('aria-invalid', String(Boolean(msg)));
      const described = [field.id === 'f-phone' ? 'f-phone-hint' : '', msg ? err.id : ''].filter(Boolean).join(' ');
      if (described) field.setAttribute('aria-describedby', described); else field.removeAttribute('aria-describedby');
      err.textContent = msg;
      err.hidden = !msg;
      return !msg;
    };
    form.querySelectorAll('input, select').forEach((f) => f.addEventListener('blur', () => { if (f.value || f.getAttribute('aria-invalid')) check(f); }));
    form.querySelectorAll('input, select').forEach((f) => f.addEventListener('input', () => { if (f.getAttribute('aria-invalid') === 'true') check(f); }));

    // campaign parameters: kept for this visit and sent with the request
    const CAMPAIGN = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'];
    const params = new URLSearchParams(location.search);
    CAMPAIGN.forEach((k) => {
      let v = params.get(k) || '';
      try { if (v) sessionStorage.setItem('ops-' + k, v); else v = sessionStorage.getItem('ops-' + k) || ''; } catch { /* private mode */ }
      const field = form.querySelector(`input[name="${k}"]`);
      if (field) field.value = v.slice(0, 200);
    });
    const track = () => { if (window.opsTrack) window.opsTrack('lead'); };

    const say = (html) => { status.innerHTML = html; status.hidden = false; };
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fields = [...form.querySelectorAll('input, select')];
      const bad = fields.filter((f) => !check(f));
      if (bad.length) { bad[0].focus(); return; }
      const data = Object.fromEntries(new FormData(form));
      data.phone = latin(data.phone).replace(/[\s-]/g, '');
      const cfg = window.OPSONE_LANDING || {};

      if (cfg.demoEndpoint) {
        submit.disabled = true;
        submit.textContent = 'جارٍ الإرسال…';
        status.hidden = true;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 15000);
        try {
          const res = await fetch(cfg.demoEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ ...data, page: location.href, sentAt: new Date().toISOString() }),
            signal: ctrl.signal,
          });
          if (!res.ok) throw new Error(String(res.status));
          track();
          form.reset();
          CAMPAIGN.forEach((k) => { try { const f = form.querySelector(`input[name="${k}"]`); f.value = sessionStorage.getItem('ops-' + k) || ''; } catch { /* private mode */ } });
          say(`وصل طلبك. سنتواصل معك على <span dir="ltr">${esc(data.phone)}</span> لتحديد موعد العرض.`);
        } catch {
          say('تعذّر إرسال الطلب. تحقّق من اتصالك وحاول مرة أخرى بعد قليل.');
        } finally {
          clearTimeout(timer);
          submit.disabled = false;
          submit.textContent = label;
        }
        return;
      }

      if (cfg.demoEmail) {
        const body = [`الاسم: ${data.name}`, `المنشأة: ${data.company}`, `الجوال: ${data.phone}`, `يبيع: ${data.trade}`, `المدينة: ${data.city || '-'}`].join('\n');
        track();
        location.href = `mailto:${encodeURIComponent(cfg.demoEmail)}?subject=${encodeURIComponent('طلب عرض تجريبي لـ OpsOne')}&body=${encodeURIComponent(body)}`;
        say(`فتحنا بريدك برسالة جاهزة إلى <span dir="ltr">${esc(cfg.demoEmail)}</span>. يصلنا طلبك بعد أن ترسلها من هناك.`);
        return;
      }

      say('لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد.');
    });
  }
})();
