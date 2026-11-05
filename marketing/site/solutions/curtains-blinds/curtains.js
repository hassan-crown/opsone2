/* OpsOne sector page: curtains and blinds. The page's own forms, on top of ../sector.js (hero line, form, footer).
   One signature moment: the curtains over the fixes draw open as they reach the reader. CSS transitions only. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- the demo order: choose a room, its windows and its lines stay lit ---------- */
  const order = document.querySelector('.corder');
  if (order) {
    const opts = [...order.querySelectorAll('.rooms__opt')];
    const parts = [...order.querySelectorAll('.house__room, .cquote tbody')];
    const pick = (room) => {
      opts.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.room === room)));
      order.classList.toggle('is-filtered', room !== 'all');
      parts.forEach((p) => p.classList.toggle('is-on', p.dataset.room === room));
    };
    opts.forEach((b) => b.addEventListener('click', () => pick(b.dataset.room)));
  }

  /* ---------- pains: each curtain starts closed and draws open once it is in view; a press closes or opens it ---------- */
  const drapes = [...document.querySelectorAll('.drape')];
  const setDrape = (el, open) => {
    el.classList.toggle('is-open', open);
    el.querySelector('.drape__btn').textContent = open ? 'أغلق الستارة' : 'افتح الستارة';
  };
  if (drapes.length && !reduce && 'IntersectionObserver' in window) {
    // closed only for what is still below the fold, so nothing the reader already sees moves on its own
    const below = drapes.filter((el) => el.getBoundingClientRect().top > innerHeight * 0.9);
    below.forEach((el) => setDrape(el, false));
    const io = new IntersectionObserver((es) => es.forEach((e) => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      const i = below.indexOf(e.target) % 3;
      setTimeout(() => setDrape(e.target, true), 120 + i * 140);
    }), { rootMargin: '0px 0px -30% 0px' });
    below.forEach((el) => io.observe(el));
  }
  drapes.forEach((el) => {
    const toggle = () => setDrape(el, !el.classList.contains('is-open'));
    el.querySelector('.drape__btn').addEventListener('click', (ev) => { ev.stopPropagation(); toggle(); });
    // the whole window is a convenience target for a pointer; the button stays the keyboard and screen-reader control
    el.querySelector('.drape__win').addEventListener('click', (ev) => { if (!getSelection().toString()) toggle(); });
  });

  /* ---------- channels: tabs hanging from the rail ---------- */
  const tabs = [...document.querySelectorAll('.rail [role="tab"]')];
  const show = (tab, focus) => {
    tabs.forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      const panel = document.getElementById(t.getAttribute('aria-controls'));
      panel.hidden = !on;
      if (on && !reduce) { panel.classList.remove('is-in'); void panel.offsetWidth; panel.classList.add('is-in'); }
    });
    if (focus) tab.focus();
  };
  // panels are all visible without script; the script hides all but the selected one
  tabs.forEach((t) => { document.getElementById(t.getAttribute('aria-controls')).hidden = t.getAttribute('aria-selected') !== 'true'; });
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => show(t));
    t.addEventListener('keydown', (e) => {
      const rtl = document.dir === 'rtl';
      const step = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1 }[e.key];
      if (step) { e.preventDefault(); show(tabs[(i + step + tabs.length) % tabs.length], true); }
      else if (e.key === 'Home') { e.preventDefault(); show(tabs[0], true); }
      else if (e.key === 'End') { e.preventDefault(); show(tabs[tabs.length - 1], true); }
    });
  });

})();
