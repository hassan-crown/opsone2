/* OpsOne landing: the live order path, the people tabs, the demo form and the motion layer.
   Everything works without this file except the switches; the page is complete in the HTML. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');

  /* ---------- many into one: the scattered squares settle into one order as the section scrolls ---------- */
  const many = document.querySelector('[data-many]');
  if (many && !reduce.matches) {
    let seed = 7;
    const rnd = () => ((seed = (seed * 1664525 + 1013904223) % 4294967296) / 4294967296);
    const sets = [...many.querySelectorAll('svg')].map((svg) => {
      const [, , w, h] = svg.getAttribute('viewBox').split(' ').map(Number);
      return [...svg.querySelectorAll('rect')].map((r) => {
        const x = +r.getAttribute('x'), y = +r.getAttribute('y');
        return { r, dx: rnd() * (w - 12) - x, dy: rnd() * (h - 12) - y, lag: rnd() * 0.25 };
      });
    });
    let ticking = false, visible = false;
    const ease = (t) => 1 - Math.pow(1 - t, 3);
    const draw = () => {
      ticking = false;
      const box = many.getBoundingClientRect();
      const vh = innerHeight;
      const p = Math.min(1, Math.max(0, (vh - box.top) / (vh * 0.75 + box.height * 0.2)));
      for (const set of sets) for (const s of set) {
        const t = ease(Math.min(1, Math.max(0, (p - s.lag) / (1 - s.lag))));
        s.r.style.transform = t >= 1 ? '' : `translate(${(s.dx * (1 - t)).toFixed(1)}px, ${(s.dy * (1 - t)).toFixed(1)}px)`;
      }
    };
    const onScroll = () => { if (visible && !ticking) { ticking = true; requestAnimationFrame(draw); } };
    new IntersectionObserver(([e]) => { visible = e.isIntersecting; if (visible) onScroll(); }, { rootMargin: '20% 0px' }).observe(many);
    addEventListener('scroll', onScroll, { passive: true });
    addEventListener('resize', onScroll);
    draw();
  }

  /* ---------- the live order path: trade switch ---------- */
  const trades = {
    kitchen: { item: 'مطبخ أكريليك مطفي بجزيرة وسطح كوارتز، 7.8 م', total: '48,300', vat: '6,300', half: '24,150', designer: 'المصمم', shop: 'ورشة النجارة' },
    aluminium: { item: 'نوافذ وأبواب ألمنيوم بقطع حراري لفيلا، 14 فتحة', total: '62,100', vat: '8,100', half: '31,050', designer: 'مهندس التصميم', shop: 'ورشة القص والتجميع' },
    wardrobe: { item: 'خزانة ملابس بأبواب منزلقة وغرفة تبديل، 4.2 م', total: '18,400', vat: '2,400', half: '9,200', designer: 'المصمم', shop: 'ورشة النجارة' },
  };
  const path = document.querySelector('#path');
  if (path) {
    path.querySelectorAll('input[name="trade"]').forEach((input) => input.addEventListener('change', () => {
      const t = trades[input.value];
      if (!t) return;
      path.querySelectorAll('[data-t]').forEach((el) => { if (t[el.dataset.t] != null) el.textContent = t[el.dataset.t]; });
    }));

    /* a stage opens its real screen beside the list (in its row on a phone) */
    const frame = path.querySelector('.path__shot');
    const frameImg = frame && frame.querySelector('img');
    const caption = path.querySelector('.path__frame figcaption');
    const rows = [...path.querySelectorAll('.stage__row')];
    const fine = matchMedia('(hover: hover) and (pointer: fine)');
    let current = rows[0];
    let swap;
    const select = (row) => {
      if (row === current) return;
      current = row;
      rows.forEach((r) => r.setAttribute('aria-pressed', String(r === row)));
      if (!frameImg) return;
      caption.textContent = row.dataset.cap;
      clearTimeout(swap);
      const apply = () => { frameImg.src = row.dataset.shot; frameImg.style.objectPosition = row.dataset.pos; frame.classList.remove('is-swapping'); };
      if (reduce.matches) { apply(); return; }
      frame.classList.add('is-swapping');
      swap = setTimeout(apply, 180);
    };
    let warmed = false;
    const warm = () => { if (warmed) return; warmed = true; rows.forEach((r) => { new Image().src = r.dataset.shot; }); };
    window.__opsPath = { rows, select, warm };
    rows.forEach((row) => {
      row.addEventListener('click', () => { warm(); select(row); if (window.__opsPin) window.__opsPin(rows.indexOf(row)); });
      row.addEventListener('focus', () => { warm(); select(row); });
      row.addEventListener('pointerenter', () => { if (fine.matches) { warm(); select(row); } });
    });
  }

  /* ---------- people: tabs switch the filmed screens ---------- */
  const tablist = document.querySelector('.people__tabs');
  const films = [...document.querySelectorAll('[data-film-item]')];
  let filmInView = false;
  const playActive = () => films.forEach((f) => {
    const v = f.querySelector('video');
    if (!f.hidden && filmInView && !reduce.matches) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause();
  });
  if (films.length && 'IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => { filmInView = e.isIntersecting; playActive(); }, { threshold: 0.25 }).observe(document.querySelector('.film'));
  }
  if (tablist) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')];
    const panel = document.getElementById('panel-people');
    const activate = (tab, focus) => {
      tabs.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      panel.setAttribute('aria-labelledby', tab.id);
      const key = tab.dataset.film;
      panel.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
      films.forEach((f) => { f.hidden = f.dataset.filmItem !== key; });
      playActive();
      if (window.gsap && !reduce.matches) {
        const on = films.find((f) => !f.hidden);
        gsap.fromTo(on, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.6, ease: 'expo.out' });
        gsap.fromTo(panel.querySelector('[data-panel]:not([hidden])').children, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: 0.5, stagger: 0.07, ease: 'expo.out' });
      }
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => activate(tab));
      tab.addEventListener('keydown', (e) => {
        // right-to-left: ArrowLeft moves forward
        const step = { ArrowLeft: 1, ArrowRight: -1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        activate(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
  }

  /* ---------- GSAP: entrances, the hero window's choreography, the pinned order path, counters ---------- */
  const fmt = (n, d = 2) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  const runMotion = () => {
    if (!window.gsap || !window.ScrollTrigger || reduce.matches) return;
    gsap.registerPlugin(ScrollTrigger);

    // hero: the window rises flat out of a tilt, then the order moves on
    const app = document.querySelector('.app');
    if (app) {
      const q = (sel) => app.querySelector(sel);
      const make = q('[data-stg="make"]'), install = q('[data-stg="install"]');
      const toast = q('[data-toast]');
      const counters = [...app.querySelectorAll('[data-count]')];
      const tl = gsap.timeline({ delay: 0.35 });
      tl.from(app, { y: 90, rotateX: 16, scale: 0.94, opacity: 0, duration: 1.3, ease: 'expo.out' })
        .from(app.querySelectorAll('.panel'), { y: 26, opacity: 0, stagger: 0.09, duration: 0.8, ease: 'expo.out' }, 0.45)
        .from(app.querySelectorAll('.stg__row'), { x: 18, opacity: 0, stagger: 0.06, duration: 0.6, ease: 'expo.out' }, 0.6);
      counters.forEach((el, i) => {
        const to = +el.dataset.count, from = i === 2 ? 48300 : 0, o = { v: from };
        tl.fromTo(o, { v: from }, { v: to, duration: 1.4, ease: 'power3.out', onUpdate: () => { el.textContent = fmt(o.v); } }, 0.8);
      });
      tl.from(app.querySelector('[data-meter]'), { scaleX: 0, duration: 1.4, ease: 'power3.out' }, 0.8)
        .from(make.querySelector('.stg__bar i'), { scaleX: 0, duration: 1.4, ease: 'power3.out' }, 0.8)
        .from(toast, { y: 40, opacity: 0, duration: 0.7, ease: 'expo.out' }, 1.6)
        // the second beat: manufacturing finishes, installation starts
        .to(make.querySelector('.stg__bar i'), { scaleX: 1, duration: 0.9, ease: 'power2.inOut' }, 3.4)
        .call(() => {
          app.querySelector('[data-task]').classList.add('is-done');
          make.classList.replace('is-now', 'is-done');
          const c1 = make.querySelector('.chip'); c1.className = 'chip chip--ok'; c1.textContent = 'مكتملة';
          install.classList.add('is-now');
          const c2 = install.querySelector('.chip'); c2.className = 'chip chip--info'; c2.textContent = 'جارية';
          const st = app.querySelector('[data-status]'); st.textContent = 'قيد التركيب';
        }, null, 4.3)
        .fromTo([make.querySelector('.chip'), install.querySelector('.chip'), app.querySelector('[data-status]')], { scale: 0.7, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.5, stagger: 0.08, ease: 'back.out(1.6)', immediateRender: false }, 4.32)
        .to(install.querySelector('.stg__bar i'), { scaleX: 0.18, duration: 0.9, ease: 'power2.out' }, 4.5);
      gsap.to(app, { y: -50, ease: 'none', scrollTrigger: { trigger: '.hero__stage', start: 'top 40%', end: 'bottom top', scrub: true } });
    }

    // every section: heading, then text, then picture, staggered once
    document.querySelectorAll('main > section').forEach((sec) => {
      const parts = sec.querySelectorAll('[data-reveal]');
      if (!parts.length) return;
      gsap.from(parts, { y: 32, opacity: 0, duration: 0.9, stagger: 0.08, ease: 'expo.out', scrollTrigger: { trigger: sec, start: 'top 82%', once: true } });
    });
    const board = document.querySelector('.board');
    if (board) {
      gsap.from(board.querySelectorAll('.tile'), { y: 28, opacity: 0, duration: 0.8, stagger: { each: 0.05, grid: 'auto', from: 'start' }, ease: 'expo.out', scrollTrigger: { trigger: board, start: 'top 80%', once: true } });
      gsap.from(board.querySelectorAll('.tile__ui'), { xPercent: -8, duration: 1.4, stagger: 0.05, ease: 'expo.out', scrollTrigger: { trigger: board, start: 'top 80%', once: true } });
      board.querySelectorAll('[data-count]').forEach((el) => {
        const o = { v: 0 }, to = +el.dataset.count;
        gsap.to(o, { v: to, duration: 1.6, ease: 'power3.out', onUpdate: () => { el.textContent = fmt(o.v); }, scrollTrigger: { trigger: el, start: 'top 90%', once: true } });
      });
      board.querySelectorAll('[data-meter]').forEach((el) => gsap.from(el, { scaleX: 0, duration: 1.6, ease: 'power3.out', scrollTrigger: { trigger: el, start: 'top 90%', once: true } }));
      gsap.from(board.querySelectorAll('.ui-thread .ui-wa'), { y: 20, opacity: 0, stagger: 0.35, duration: 0.6, ease: 'expo.out', scrollTrigger: { trigger: '.tile--wa', start: 'top 85%', once: true } });
    }

    // the order path holds still on wide screens while the stages pass, one screen per part of the order
    const mm = gsap.matchMedia();
    mm.add('(min-width: 1024px) and (min-height: 700px)', () => {
      const body = document.querySelector('.path__body');
      const P = window.__opsPath;
      if (!body || !P) return;
      const n = P.rows.length;
      const st = ScrollTrigger.create({
        trigger: body, start: 'top 8%', end: () => '+=' + Math.round(innerHeight * 2.2), pin: true, pinSpacing: true,
        onUpdate: (self) => { const i = Math.min(n - 1, Math.floor(self.progress * n)); if (P.rows[i].getAttribute('aria-pressed') !== 'true') { P.warm(); P.select(P.rows[i]); } },
      });
      window.__opsPin = (i) => { const y = st.start + ((i + 0.5) / n) * (st.end - st.start); scrollTo({ top: y, behavior: 'smooth' }); };
      return () => { window.__opsPin = null; };
    });
  };
  runMotion();
  addEventListener('load', () => { if (window.ScrollTrigger) ScrollTrigger.refresh(); }, { once: true });

  /* ---------- demo request form ---------- */
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
      trade: (v) => (v ? '' : 'اختر نشاط المنشأة.'),
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
          form.reset();
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
        const body = [`الاسم: ${data.name}`, `المنشأة: ${data.company}`, `الجوال: ${data.phone}`, `النشاط: ${data.trade}`, `المدينة: ${data.city || '-'}`].join('\n');
        location.href = `mailto:${encodeURIComponent(cfg.demoEmail)}?subject=${encodeURIComponent('طلب عرض تجريبي لـ OpsOne')}&body=${encodeURIComponent(body)}`;
        say(`فتحنا بريدك برسالة جاهزة إلى <span dir="ltr">${esc(cfg.demoEmail)}</span>. يصلنا طلبك بعد أن ترسلها من هناك.`);
        return;
      }

      say('لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد.');
    });
  }
})();
