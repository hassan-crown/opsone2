/* Smart home, lighting and security page: the system filter and device lines lighting their points on the villa plan,
   the cases arriving as they reach the reader, and the payment plan switch on the flow. CSS transitions and WAAPI only. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- device schedule <-> plan ---------- */
  const sched = document.querySelector('.sched');
  if (sched) {
    const svg = sched.querySelector('.vplan svg');
    const pts = [...svg.querySelectorAll('.pt')];
    const devs = [...sched.querySelectorAll('.dev:not(:disabled)')];
    const grps = [...sched.querySelectorAll('.grp')];
    const legend = [...sched.querySelectorAll('.vplan__legend li')];
    const filters = [...sched.querySelectorAll('[data-filter]')];
    const sums = JSON.parse(document.getElementById('sched-sums').textContent);
    const sumLabel = document.getElementById('sum-label'), sumVal = document.getElementById('sum-val');
    let sys = 'all', code = null;
    const paint = () => {
      pts.forEach((p) => {
        p.classList.toggle('is-on', code ? p.dataset.code === code : sys !== 'all' && p.dataset.sys === sys);
        p.classList.toggle('is-hot', code != null && p.dataset.code === code);
      });
      svg.classList.toggle('is-filtered', code != null || sys !== 'all');
      devs.forEach((d) => d.setAttribute('aria-pressed', String(d.dataset.code === code)));
      grps.forEach((g) => g.classList.toggle('is-on', g.dataset.sys === sys));
      legend.forEach((l) => l.classList.toggle('is-off', sys !== 'all' && l.dataset.sys !== sys));
    };
    filters.forEach((b) => b.addEventListener('click', () => {
      sys = b.dataset.filter;
      code = null;
      sched.dataset.show = sys;
      filters.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      sumLabel.textContent = `مجموع ${sums[sys].label}`;
      sumVal.textContent = sums[sys].sum;
      paint();
      if (!reduce && sumVal.animate) sumVal.animate([{ opacity: 0.3, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: EASE_OUT });
    }));
    const hold = (c) => { code = c; paint(); };
    devs.forEach((d) => {
      d.addEventListener('click', () => hold(d.getAttribute('aria-pressed') === 'true' ? null : d.dataset.code));
      d.addEventListener('focus', () => hold(d.dataset.code));
      if (fine) {
        d.addEventListener('pointerenter', () => hold(d.dataset.code));
        d.addEventListener('pointerleave', () => { if (!devs.includes(document.activeElement)) hold(null); });
      }
    });
    sched.addEventListener('focusout', (e) => { if (!sched.contains(e.relatedTarget)) hold(null); });
    // the plan is wired once: devices appear system by system when it first reaches the reader
    if (!reduce && document.body.animate && 'IntersectionObserver' in window) {
      const order = ['light', 'control', 'cctv', 'security'];
      const io = new IntersectionObserver(([e]) => {
        if (!e.isIntersecting) return;
        io.disconnect();
        pts.forEach((p) => p.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 260, delay: 120 + order.indexOf(p.dataset.sys) * 160 + Math.random() * 90, easing: 'ease-out', fill: 'backwards' }));
      }, { threshold: 0.4 });
      io.observe(svg);
    }
  }

  /* ---------- cases: each one arrives with its fix as it reaches the reader ---------- */
  const cases = [...document.querySelectorAll('.case')];
  if (cases.length && !reduce && 'IntersectionObserver' in window) {
    document.querySelector('.cases').classList.add('is-live');
    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -20% 0px' });
    cases.forEach((c) => io.observe(c));
  }

  /* ---------- flow: switch the payment plan drawn on the stages ---------- */
  const planOpts = [...document.querySelectorAll('.flowbar [data-plan]')];
  planOpts.forEach((b) => b.addEventListener('click', () => {
    planOpts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    const shown = [];
    document.querySelectorAll('.paym').forEach((m) => { const on = m.dataset.plan === b.dataset.plan; m.hidden = !on; if (on) shown.push(m); });
    if (!reduce && document.body.animate) shown.forEach((m, i) => m.animate([{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 240, delay: i * 60, easing: EASE_OUT, fill: 'backwards' }));
  }));
})();
