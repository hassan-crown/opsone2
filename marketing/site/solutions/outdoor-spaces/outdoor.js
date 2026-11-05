/* Outdoor spaces page, after ../sector.js (which keeps the hero line, the footer credit and the demo form).
   The six drawings draw themselves once, the quote and the yard's numbered marks light each other, the pains fill the
   order's way as they are read, the channel tabs, and the payment plan pinned on the flow. GSAP + ScrollTrigger only for
   the flow's bars on wide screens (loaded on demand, as on the home page); everything else is CSS and the observers. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const io = 'IntersectionObserver' in window;
  const once = (el, fn, t = 0.3) => {
    if (!el) return;
    if (!io) { fn(); return; }
    const o = new IntersectionObserver(([e]) => { if (e.isIntersecting) { o.disconnect(); fn(); } }, { threshold: t });
    o.observe(el);
  };

  /* ---------- the six kinds of work: each line drawing traces itself once ---------- */
  const kinds = document.querySelector('.kinds');
  if (kinds) {
    kinds.querySelectorAll('.kind__art svg path, .kind__art svg rect, .kind__art svg circle').forEach((p) => {
      try { p.style.setProperty('--len', Math.ceil(p.getTotalLength()) + 1); } catch { p.style.setProperty('--len', 0); }
    });
    if (reduce) kinds.classList.add('is-drawn'); else once(kinds, () => kinds.classList.add('is-drawn'), 0.25);
  }

  /* ---------- the demo order: a line of the quote lights its place in the yard, and back ---------- */
  const site = document.querySelector('.site');
  const lines = [...document.querySelectorAll('.ledger__line')];
  if (site && lines.length) {
    const parts = [...site.querySelectorAll('[data-part]')];
    // a click pins a part (aria-pressed); hovering or focusing a line only previews it until the pointer leaves
    let pinned = null;
    const show = (key) => {
      parts.forEach((p) => p.classList.toggle('is-lit', key != null && p.dataset.part === key));
      site.classList.toggle('has-lit', key != null);
    };
    const pin = (key) => {
      pinned = key;
      lines.forEach((l) => l.setAttribute('aria-pressed', String(l.dataset.part === key)));
      show(key);
    };
    const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
    lines.forEach((l) => {
      l.addEventListener('click', () => pin(pinned === l.dataset.part ? null : l.dataset.part));
      l.addEventListener('focus', () => show(l.dataset.part));
      l.addEventListener('blur', () => show(pinned));
      if (fine) {
        l.addEventListener('pointerenter', () => show(l.dataset.part));
        l.addEventListener('pointerleave', () => show(lines.includes(document.activeElement) ? document.activeElement.dataset.part : pinned));
      }
    });
    parts.forEach((p) => p.addEventListener('click', () => {
      const on = pinned === p.dataset.part;
      pin(on ? null : p.dataset.part);
      // below 1100px the sheet sits above the quote: bring the matching line into view
      if (!on && !matchMedia('(min-width: 1101px)').matches) lines.find((l) => l.dataset.part === p.dataset.part)?.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
    }));
    // the marks land on the plan one after another, in the quote's order
    if (!reduce && document.body.animate) once(site, () => site.querySelectorAll('.pin').forEach((g, i) => g.animate([{ opacity: 0, transform: 'translateY(-8px)' }, { opacity: 1, transform: 'none' }], { duration: 300, delay: 120 + i * 70, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'backwards' })), 0.35);
  }

  /* ---------- pains: the way fills as each stop is read ---------- */
  const trail = document.querySelector('.trail');
  if (trail) {
    const items = [...trail.querySelectorAll('.trail__item')];
    const mark = () => {
      const on = items.filter((x) => x.classList.contains('is-on')).length;
      trail.style.setProperty('--p', (on / items.length).toFixed(3));
    };
    if (reduce || !io) { items.forEach((x) => x.classList.add('is-on')); mark(); }
    else {
      const o = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-on'); o.unobserve(e.target); mark(); } }), { rootMargin: '0px 0px -30% 0px' });
      items.forEach((x) => o.observe(x));
    }
  }

  /* ---------- channels: tabs (arrow keys move between them, as the pattern expects) ---------- */
  const tabs = [...document.querySelectorAll('.tabs__tab')];
  const select = (t, focus) => {
    tabs.forEach((x) => {
      const on = x === t;
      x.setAttribute('aria-selected', String(on));
      x.tabIndex = on ? 0 : -1;
      document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) t.focus();
    const panel = document.getElementById(t.getAttribute('aria-controls'));
    if (!reduce && panel.animate) panel.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 240, easing: 'cubic-bezier(0.23, 1, 0.32, 1)' });
  };
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => { if (t.getAttribute('aria-selected') !== 'true') select(t); });
    t.addEventListener('keydown', (e) => {
      const rtl = document.dir === 'rtl';
      const step = { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1, Home: -i, End: tabs.length - 1 - i }[e.key];
      if (step === undefined) return;
      e.preventDefault();
      select(tabs[(i + step + tabs.length) % tabs.length], true);
    });
  });

  /* ---------- setup: switch the payment plan pinned on the flow ---------- */
  const pinsEl = document.getElementById('plan-pins');
  const planData = document.getElementById('plan-data');
  if (pinsEl && planData) {
    const plans = JSON.parse(planData.textContent);
    const opts = [...document.querySelectorAll('.plans__opt')];
    opts.forEach((b) => b.addEventListener('click', () => {
      opts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      const plan = plans[Number(b.dataset.plan)];
      const old = [...pinsEl.children];
      // pins that exist in both plans slide to their new day; the rest are added or removed
      plan.forEach((p, k) => {
        let li = old[k];
        if (!li) { li = document.createElement('li'); li.innerHTML = '<b class="nums"></b><span></span>'; pinsEl.append(li); }
        li.style.setProperty('--at', p.day);
        li.style.setProperty('--row', p.row);
        li.querySelector('b').textContent = `${p.pct}%`;
        li.querySelector('span').textContent = p.when;
      });
      old.slice(plan.length).forEach((li) => li.remove());
    }));
  }

  /* ---------- setup: the bars grow along the days as the flow scrolls by (wide screens only) ---------- */
  const gantt = document.querySelector('.gantt');
  const load = (src) => new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = rej; document.head.append(s); });
  if (gantt && !reduce && matchMedia('(min-width: 1024px)').matches) {
    load('../../vendor/gsap.min.js').then(() => load('../../vendor/ScrollTrigger.min.js')).then(() => {
      const { gsap, ScrollTrigger } = window;
      gsap.registerPlugin(ScrollTrigger);
      gsap.from(gantt.querySelectorAll('.gantt__bar'), {
        scaleX: 0, ease: 'power2.out', stagger: 0.12,
        scrollTrigger: { trigger: gantt.querySelector('.gantt__rows'), start: 'top 80%', end: 'bottom 55%', scrub: 0.6 },
      });
      gsap.from(gantt.querySelectorAll('.gantt__pins li'), {
        opacity: 0, stagger: 0.12, duration: 0.4, ease: 'power2.out',
        scrollTrigger: { trigger: gantt.querySelector('.gantt__axis'), start: 'top 85%' },
      });
      if (document.readyState === 'complete') ScrollTrigger.refresh(); else addEventListener('load', () => ScrollTrigger.refresh(), { once: true });
    }).catch(() => {});
  }
})();
