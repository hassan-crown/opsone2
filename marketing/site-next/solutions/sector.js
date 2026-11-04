/* OpsOne sector pages: the order's line in the hero, the plan linked to the quote, the pains crossed out,
   the channel panes, the payment-plan switch and the demo form. CSS transitions and the Web Animations API only. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isRTL = document.dir === 'rtl';
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const centre = (el) => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; };
  const once = (el, fn, t = 0.4) => {
    if (!el) return;
    if (!('IntersectionObserver' in window)) { fn(); return; }
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); fn(); } }, { threshold: t });
    io.observe(el);
  };

  /* ---------- hero: the order on the sector's line (the only line of places on the page) ---------- */
  const dataEl = document.getElementById('station-data');
  const route = document.getElementById('route');
  if (dataEl && route) {
    const { live, stations: data } = JSON.parse(dataEl.textContent);
    const screens = Object.fromEntries(data.map((s) => [s.key, s.screen]));
    const stationsEl = document.getElementById('stations');
    const stations = [...stationsEl.querySelectorAll('.station')];
    const fill = document.getElementById('fill'), ticket = document.getElementById('ticket');
    const screen = document.getElementById('screen'), body = document.getElementById('screen-body'), notch = document.getElementById('notch');
    const liveKey = data[live].key;
    const vertical = () => matchMedia('(max-width: 760px)').matches;
    const dotOf = (k) => stations.find((s) => s.dataset.k === k).querySelector('.dot');
    const cell = (v) => (Array.isArray(v) ? `<span class="pill-s pill-s--${v[0]}">${v[1]}</span>` : `<b class="nums">${v}</b>`);
    const render = (k) => { const s = screens[k]; return `<div class="shead"><b class="nums">${s.title}</b><small>${s.room}</small></div><ul class="srows">${s.rows.map((r) => `<li><span class="nums">${r[0]}</span>${cell(r[1])}</li>`).join('')}</ul>`; };
    let current = liveKey, swapTimer, drawn = false;
    const place = (k) => {
      if (vertical()) {
        const st = stations.find((s) => s.dataset.k === k);
        if (screen.previousElementSibling !== st) st.after(screen);
        screen.style.setProperty('--x', '0px');
        return;
      }
      const box = route.getBoundingClientRect(), [dx] = centre(dotOf(k));
      const w = screen.offsetWidth, cx = dx - box.left;
      const fromStart = isRTL ? box.width - cx : cx;
      const start = Math.max(0, Math.min(box.width - w, fromStart - w / 2));
      screen.style.setProperty('--x', `${isRTL ? -start : start}px`);
      const n = fromStart - start - 7;
      notch.style.setProperty('--n', `${isRTL ? -n : n}px`);
    };
    const fillTo = () => {
      if (!drawn) return;
      const track = fill.parentElement.getBoundingClientRect(), [lx, ly] = centre(dotOf(liveKey));
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
      const box = stationsEl.getBoundingClientRect(), [lx] = centre(dotOf(liveKey)), [sx] = centre(dotOf(data[0].key));
      ticket.style.left = `${lx - box.left}px`;
      return sx - lx;
    };
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
      stations.forEach((s, i) => { if (i <= live) setTimeout(() => s.classList.add(i === live ? 'is-live' : 'is-done'), DRAW * (i / Math.max(1, live))); });
    };
    select(liveKey, false);
    placeTicket();
    addEventListener('resize', () => { place(current); placeTicket(); fillTo(); });
    requestAnimationFrame(() => requestAnimationFrame(play));
  }

  /* ---------- the demo order: a line of the quote lights its part of the plan, and back ---------- */
  const plan = document.querySelector('.kplan svg');
  const lines = [...document.querySelectorAll('.boq__line')];
  if (plan && lines.length) {
    const parts = [...plan.querySelectorAll('[data-part]')];
    const light = (key) => {
      lines.forEach((l) => l.setAttribute('aria-pressed', String(l.dataset.part === key)));
      parts.forEach((p) => p.classList.toggle('is-lit', key != null && p.dataset.part.split(' ').includes(key)));
      plan.classList.toggle('has-lit', key != null);
    };
    lines.forEach((l) => {
      l.addEventListener('click', () => light(l.getAttribute('aria-pressed') === 'true' ? null : l.dataset.part));
      if (matchMedia('(hover: hover) and (pointer: fine)').matches) {
        l.addEventListener('pointerenter', () => light(l.dataset.part));
        l.addEventListener('pointerleave', () => { if (!lines.some((x) => x === document.activeElement)) light(null); });
      }
      l.addEventListener('focus', () => light(l.dataset.part));
    });
    parts.forEach((p) => p.addEventListener('click', () => light(p.dataset.part.split(' ')[0])));
    // the order assembles once: each line of the quote arrives in turn
    if (!reduce && document.body.animate) once(document.querySelector('.boq'), () => lines.forEach((l, i) => l.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 260, delay: i * 60, easing: EASE_OUT, fill: 'backwards' })), 0.35);
  }

  /* ---------- pains: each one is crossed out and its fix written beside it as it reaches the reader ---------- */
  const swaps = [...document.querySelectorAll('.swap__item')];
  if (swaps.length) {
    document.querySelector('.swap').classList.add('is-live');
    if (reduce || !('IntersectionObserver' in window)) swaps.forEach((s) => s.classList.add('is-fixed'));
    else {
      const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-fixed'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -35% 0px' });
      swaps.forEach((s) => io.observe(s));
    }
  }

  /* ---------- channels: one pane open at a time ---------- */
  const panes = [...document.querySelectorAll('.pane')];
  panes.forEach((p) => p.querySelector('.pane__btn').addEventListener('click', () => {
    panes.forEach((x) => { const on = x === p; x.classList.toggle('is-open', on); x.querySelector('.pane__btn').setAttribute('aria-expanded', String(on)); });
  }));

  /* ---------- ready-made setup: switch between the payment plans the system ships with ---------- */
  const opts = [...document.querySelectorAll('.splits__opt')];
  opts.forEach((b) => b.addEventListener('click', () => {
    opts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    document.querySelectorAll('.splitbar').forEach((s) => { s.hidden = s.dataset.plan !== b.dataset.plan; });
    const bar = document.querySelector(`.splitbar[data-plan="${b.dataset.plan}"]`);
    if (!reduce && bar.animate) [...bar.children].forEach((li, i) => li.animate([{ transform: 'scaleX(0.92)', opacity: 0 }, { transform: 'none', opacity: 1 }], { duration: 220, delay: i * 40, easing: EASE_OUT, fill: 'backwards' }));
  }));

  /* ---------- footer credit (as on the home page) ---------- */
  const devUrl = ((window.OPSONE_LANDING || {}).developerUrl || '').trim();
  const dev = document.querySelector('[data-developer]');
  if (dev && /^https:\/\//.test(devUrl)) { const a = document.createElement('a'); a.href = devUrl; a.target = '_blank'; a.rel = 'noopener'; a.textContent = dev.textContent; dev.replaceWith(a); }

  /* ---------- demo request form (behaviour unchanged from the home page, plus the sector field) ---------- */
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
          const res = await fetch(cfg.demoEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ ...data, page: location.href, sentAt: new Date().toISOString() }), signal: ctrl.signal });
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
        const body = [`الاسم: ${data.name}`, `المنشأة: ${data.company}`, `الجوال: ${data.phone}`, `يبيع: ${data.trade}`, `النشاط: ${data.sector || '-'}`, `المدينة: ${data.city || '-'}`].join('\n');
        track();
        location.href = `mailto:${encodeURIComponent(cfg.demoEmail)}?subject=${encodeURIComponent('طلب عرض تجريبي لـ OpsOne')}&body=${encodeURIComponent(body)}`;
        say(`فتحنا بريدك برسالة جاهزة إلى <span dir="ltr">${esc(cfg.demoEmail)}</span>. يصلنا طلبك بعد أن ترسلها من هناك.`);
        return;
      }
      say('لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد.');
    });
  }
})();
