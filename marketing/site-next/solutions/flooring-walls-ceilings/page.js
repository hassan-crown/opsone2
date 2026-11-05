/* Flooring, walls and ceilings: the plan's layers, a takeoff line lighting its rooms, the ledger written in,
   and the plan laid room by room with GSAP when it first reaches the reader (desktop, motion allowed). */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- the demo order: layers, and a line of the takeoff lights its rooms ---------- */
  const body = document.querySelector('.forder__body');
  const svg = body && body.querySelector('.fplan svg');
  const rows = body ? [...body.querySelectorAll('.tk__row')] : [];
  if (body && svg) {
    const parts = [...svg.querySelectorAll('[data-part]')];
    const opts = [...document.querySelectorAll('.flayers .seg__opt')];
    const pickOf = (r) => r.querySelector('.tk__pick');
    const light = (key) => {
      rows.forEach((r) => { const on = r.dataset.part === key; r.classList.toggle('is-on', on); pickOf(r).setAttribute('aria-pressed', String(on)); });
      parts.forEach((p) => p.classList.toggle('is-lit', key != null && p.dataset.part.split(' ').includes(key)));
      svg.classList.toggle('has-lit', key != null);
    };
    opts.forEach((b) => b.addEventListener('click', () => {
      opts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      body.dataset.layer = b.dataset.layer;
      light(null);
    }));
    rows.forEach((r) => {
      const btn = pickOf(r);
      // the button carries the state for keyboards and screen readers; a click anywhere on the row does the same
      r.addEventListener('click', () => light(btn.getAttribute('aria-pressed') === 'true' ? null : r.dataset.part));
      if (fine) {
        r.addEventListener('pointerenter', () => light(r.dataset.part));
        r.addEventListener('pointerleave', () => { if (!rows.some((x) => x.contains(document.activeElement))) light(null); });
      }
      btn.addEventListener('focus', () => light(r.dataset.part));
    });
    parts.forEach((p) => p.addEventListener('click', () => {
      const key = p.dataset.part.split(' ')[0];
      light(key);
    }));
  }

  /* ---------- pains: each fix is written in as its row reaches the reader ---------- */
  const ledger = document.querySelector('.ledger');
  if (ledger && !reduce && 'IntersectionObserver' in window) {
    const lrows = [...ledger.querySelectorAll('.ledger__row')];
    ledger.classList.add('is-live');
    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); } }), { rootMargin: '0px 0px -20% 0px' });
    lrows.forEach((r) => io.observe(r));
  }

  /* ---------- the plan is laid room by room, once (GSAP, loaded only when it is needed) ---------- */
  if (!svg || reduce || !matchMedia('(min-width: 1024px)').matches || !('IntersectionObserver' in window)) return;
  const load = (src) => new Promise((ok, no) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = no; document.head.append(s); });
  const play = () => {
    const { gsap } = window;
    if (!gsap) return;
    const floors = svg.querySelectorAll('.fp__floor');
    const papers = svg.querySelectorAll('.fp__paper');
    const ceils = svg.querySelectorAll('.fp__ceil');
    const labels = svg.querySelectorAll('.fp__name, .fp__area');
    papers.forEach((l) => { const n = l.getTotalLength(); l.style.strokeDasharray = n; l.style.strokeDashoffset = n; });
    gsap.timeline({ defaults: { ease: 'power3.out' }, onComplete: () => papers.forEach((l) => { l.style.strokeDasharray = ''; l.style.strokeDashoffset = ''; }) })
      .from(floors, { opacity: 0, duration: 0.5, stagger: 0.12, clearProps: 'opacity' })
      .from(labels, { opacity: 0, y: 4, duration: 0.3, stagger: 0.04, clearProps: 'opacity,transform' }, '<0.2')
      .to(papers, { strokeDashoffset: 0, duration: 0.6, stagger: 0.15, ease: 'power2.inOut' }, '-=0.2')
      .from(ceils, { opacity: 0, duration: 0.4, stagger: 0.12, clearProps: 'opacity' }, '<')
      .from(rows, { opacity: 0, y: 6, duration: 0.3, stagger: 0.05, clearProps: 'opacity,transform' }, 0.1);
  };
  const io = new IntersectionObserver(([e]) => {
    if (!e.isIntersecting) return;
    io.disconnect();
    (window.gsap ? Promise.resolve() : load('../../vendor/gsap.min.js')).then(play, () => {});
  }, { threshold: 0.3 });
  io.observe(svg);
})();
