/* OpsOne landing: the hero order, the people tabs, the demo form and the motion layer.
   Everything works without this file except the switches; the page is complete in the HTML. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)');

  /* ---------- tabs: one small helper for the roles ---------- */
  const tabset = (list, onPick) => {
    if (!list) return null;
    const tabs = [...list.querySelectorAll('[role="tab"]')];
    const pick = (tab, focus) => {
      tabs.forEach((t) => { const on = t === tab; t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1; });
      onPick(tab);
      if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
      tab.addEventListener('click', () => pick(tab));
      tab.addEventListener('keydown', (e) => {
        // right-to-left: ArrowLeft moves forward; vertical lists also take up and down
        const step = { ArrowLeft: 1, ArrowDown: 1, ArrowRight: -1, ArrowUp: -1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step == null) return;
        e.preventDefault();
        pick(tabs[(i + step + tabs.length) % tabs.length], true);
      });
    });
    return { tabs, pick };
  };

  /* ---------- roles: tabs switch the view (film or phone) and the lines ---------- */
  const films = [...document.querySelectorAll('[data-film-item]')];
  let filmInView = false;
  const playActive = () => films.forEach((f) => {
    const v = f.querySelector('video');
    if (!v) return;
    if (!f.hidden && filmInView && !reduce.matches) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause();
  });
  if (films.length && 'IntersectionObserver' in window) {
    new IntersectionObserver(([e]) => { filmInView = e.isIntersecting; playActive(); }, { threshold: 0.2 }).observe(document.querySelector('.roles__media'));
  }
  const panel = document.getElementById('panel-roles');
  tabset(document.querySelector('.roles__tabs'), (tab) => {
    const key = tab.dataset.film;
    panel.setAttribute('aria-labelledby', tab.id);
    panel.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== key; });
    films.forEach((f) => { f.hidden = f.dataset.filmItem !== key; });
    playActive();
    if (window.gsap && !reduce.matches) {
      gsap.fromTo(films.find((f) => !f.hidden), { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.6, ease: 'expo.out' });
      gsap.fromTo(panel.querySelectorAll('[data-panel]:not([hidden]) li'), { opacity: 0, x: 16 }, { opacity: 1, x: 0, duration: 0.5, stagger: 0.07, ease: 'expo.out' });
    }
  });

  /* ---------- footer credit: the developer's name links out only once its URL is set in config.js ---------- */
  const devUrl = ((window.OPSONE_LANDING || {}).developerUrl || '').trim();
  const dev = document.querySelector('[data-developer]');
  if (dev && /^https:\/\//.test(devUrl)) {
    const link = document.createElement('a');
    link.className = 'link';
    link.href = devUrl;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = dev.textContent;
    dev.replaceWith(link);
  }

  /* ---------- the lead becomes the order: «تحويل إلى طلب» is pressed once, the first time the pair is on screen ---------- */
  const duo = document.querySelector('[data-duo]');
  if (duo && !reduce.matches && 'IntersectionObserver' in window) {
    duo.classList.add('is-before');
    const press = duo.querySelector('[data-convert]');
    const io = new IntersectionObserver(([e]) => {
      if (!e.isIntersecting) return;
      io.disconnect();
      setTimeout(() => press.classList.add('is-pressed'), 900);
      setTimeout(() => {
        duo.classList.remove('is-before');
        duo.querySelectorAll('.duo__now, .duo__done').forEach((el) => el.animate && el.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 420, easing: 'cubic-bezier(0.23, 1, 0.32, 1)' }));
      }, 1150);
    }, { threshold: 0.6 });
    io.observe(duo);
  }

  /* ---------- the eight steps: the step at the middle of the screen owns the stage ---------- */
  const steps = [...document.querySelectorAll('.step')];
  if (steps.length && 'IntersectionObserver' in window) {
    const on = (step) => {
      if (step.classList.contains('is-on')) return;
      steps.forEach((s) => s.classList.toggle('is-on', s === step));
      // the design approval replays once: the client presses «اعتماد التصميم» and the stamp lands
      const ask = step.querySelector('[data-approve]');
      if (ask && !ask.dataset.played && !reduce.matches) {
        ask.dataset.played = '1';
        ask.classList.add('is-pending');
        setTimeout(() => ask.classList.remove('is-pending'), 1400);
      }
      const ink = step.querySelector('.sign__pad path');
      if (ink && !ink.dataset.played && !reduce.matches && ink.animate) {
        ink.dataset.played = '1';
        const len = ink.getTotalLength();
        ink.animate([{ strokeDasharray: len, strokeDashoffset: len }, { strokeDasharray: len, strokeDashoffset: 0 }], { duration: 1100, easing: 'cubic-bezier(0.77, 0, 0.175, 1)', delay: 250, fill: 'backwards' });
      }
    };
    on(steps[0]);
    const io = new IntersectionObserver((entries) => entries.forEach((e) => { if (e.isIntersecting) on(e.target.closest('.step')); }), { rootMargin: '-50% 0px -50% 0px' });
    steps.forEach((s) => io.observe(s.querySelector('.step__text')));
  }

  /* ---------- the portal clip plays while it is on screen ---------- */
  document.querySelectorAll('video[data-autoplay]').forEach((v) => {
    if (!('IntersectionObserver' in window)) return;
    new IntersectionObserver(([e]) => { if (e.isIntersecting && !reduce.matches) { v.preload = 'auto'; v.play().catch(() => {}); } else v.pause(); }, { threshold: 0.3 }).observe(v);
  });

  /* ---------- GSAP: entrances, the hero window's choreography, counters ---------- */
  const fmt = (n, d = 2) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  const runMotion = () => {
    if (!window.gsap || !window.ScrollTrigger || reduce.matches) return;
    gsap.registerPlugin(ScrollTrigger);

    // hero: the window rises flat out of a tilt, then the order moves on
    const app = document.querySelector('.app');
    if (app) {
      const q = (sel) => app.querySelector(sel);
      const make = q('[data-stg="make"]'), install = q('[data-stg="install"]');
      const toast = document.querySelector('[data-toast]');
      const counters = [...app.querySelectorAll('[data-count]')];
      const tl = gsap.timeline({ delay: 0.35 });
      // the 3D tilt holds back the first paint on phones (Lighthouse LCP), so narrow screens get a plain rise
      const wide = matchMedia('(min-width: 1024px)').matches;
      tl.from(app, wide ? { y: 90, rotateX: 16, scale: 0.94, duration: 1.3, ease: 'expo.out' } : { y: 60, duration: 1.1, ease: 'expo.out' })
        .from(app.querySelectorAll('.panel'), { y: 26, stagger: 0.09, duration: 0.8, ease: 'expo.out' }, 0.45)
        .from(app.querySelectorAll('.stg__row'), { x: 18, stagger: 0.06, duration: 0.6, ease: 'expo.out' }, 0.6);
      counters.forEach((el, i) => {
        const to = +el.dataset.count, from = i === 2 ? 48300 : 0, o = { v: from };
        tl.fromTo(o, { v: from }, { v: to, duration: 1.4, ease: 'power3.out', onUpdate: () => { el.textContent = fmt(o.v); } }, 0.8);
      });
      tl.from(app.querySelector('[data-meter]'), { scaleX: 0, duration: 1.4, ease: 'power3.out' }, 0.8)
        .from(make.querySelector('.stg__bar i'), { scaleX: 0, duration: 1.4, ease: 'power3.out' }, 0.8)
        // the second beat: manufacturing finishes, installation starts
        .to(make.querySelector('.stg__bar i'), { scaleX: 1, duration: 0.9, ease: 'power2.inOut' }, 2.4)
        .call(() => {
          app.querySelector('[data-task]').classList.add('is-done');
          make.classList.replace('is-now', 'is-done');
          make.querySelector('.stg__dot').innerHTML = '<svg class="ic"><use href="#i-check"/></svg>';
          const c1 = make.querySelector('.chip'); c1.className = 'chip chip--ok'; c1.textContent = 'مكتملة';
          install.classList.add('is-now');
          const c2 = install.querySelector('.chip'); c2.className = 'chip chip--info'; c2.textContent = 'جارية';
          const st = app.querySelector('[data-status]'); st.textContent = 'قيد التركيب';
        }, null, 3.3)
        .fromTo([make.querySelector('.chip'), install.querySelector('.chip'), app.querySelector('[data-status]')], { scale: 0.92, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.4, stagger: 0.06, ease: 'expo.out', immediateRender: false }, 3.32)
        .to(install.querySelector('.stg__bar i'), { scaleX: 0.18, duration: 0.9, ease: 'power2.out' }, 3.5)
        .from(toast, { y: 24, opacity: 0, duration: 0.6, ease: 'expo.out' }, 3.7);
      gsap.to(app, { y: -50, ease: 'none', scrollTrigger: { trigger: '.hero__stage', start: 'top 40%', end: 'bottom top', scrub: true } });
    }

    // a section's title and its lead arrive once; lists and steps are already in place
    document.querySelectorAll('main > section').forEach((sec) => {
      const parts = sec.querySelectorAll('.head[data-reveal], .lead[data-reveal], .tool[data-reveal], .roles__media[data-reveal]');
      if (!parts.length) return;
      gsap.from(parts, { y: 20, opacity: 0, duration: 0.8, stagger: 0.07, ease: 'expo.out', scrollTrigger: { trigger: sec, start: 'top 82%', once: true } });
    });
    // counters and meters in the money tile
    document.querySelectorAll('.tools [data-count]').forEach((el) => {
      const o = { v: 0 }, to = +el.dataset.count;
      gsap.to(o, { v: to, duration: 1.6, ease: 'power3.out', onUpdate: () => { el.textContent = fmt(o.v); }, scrollTrigger: { trigger: el, start: 'top 90%', once: true } });
    });
    document.querySelectorAll('.tools [data-meter]').forEach((el) => gsap.from(el, { scaleX: 0, duration: 1.6, ease: 'power3.out', scrollTrigger: { trigger: el, start: 'top 90%', once: true } }));
    gsap.from('.chat .ui-wa', { y: 18, opacity: 0, stagger: 0.3, duration: 0.6, ease: 'expo.out', scrollTrigger: { trigger: '.tool--wa', start: 'top 80%', once: true } });
    gsap.from('.doc__sign svg path', { strokeDasharray: 600, strokeDashoffset: 600, duration: 1.6, ease: 'power2.inOut', scrollTrigger: { trigger: '.tool--sign', start: 'top 75%', once: true } });

    // item 9: the owner approves the draft, version 4 goes live, running orders stay on version 3
    const release = document.querySelector('[data-release]');
    if (release) {
      const press = release.querySelector('[data-publish]');
      gsap.timeline({ scrollTrigger: { trigger: '.cfg', start: 'top 60%', once: true } })
        .from(release.querySelector('.flow__row.is-new'), { y: -12, opacity: 0, duration: 0.6, ease: 'expo.out' })
        .from(release.querySelector('.flow__draft'), { y: 12, opacity: 0, duration: 0.6, ease: 'expo.out' }, 0.2)
        .call(() => press.classList.add('is-pressed'), null, 1.6)
        .call(() => { press.classList.remove('is-pressed'); release.classList.add('is-published'); }, null, 1.8)
        .fromTo(release.querySelectorAll('.flow__draft .v-new, .flow__head .v-new'), { opacity: 0, y: 6 }, { opacity: 1, y: 0, duration: 0.45, ease: 'expo.out', immediateRender: false }, 1.82);
    }
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
