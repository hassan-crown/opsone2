/* OpsOne sector page: signage, facades, exhibition stands and events. The page's own forms, on top of ../sector.js
   (hero line, payment-plan switch, form, footer). One signature moment: the flow's bars are drawn along the ruler of
   days toward the opening, with GSAP + ScrollTrigger fetched only on wide screens with motion allowed. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- the demo order: a line of the quote lights its part of the booth, and back ---------- */
  const svg = document.querySelector('.bplan svg');
  const lines = [...document.querySelectorAll('.squote__line')];
  if (svg && lines.length) {
    const parts = [...svg.querySelectorAll('[data-part]')];
    let lit = null;
    const light = (part) => {
      lit = lit === part ? null : part;
      lines.forEach((l) => l.setAttribute('aria-pressed', String(l.dataset.part === lit)));
      parts.forEach((p) => p.classList.toggle('is-lit', p.dataset.part === lit));
      svg.classList.toggle('has-lit', !!lit);
    };
    lines.forEach((l) => l.addEventListener('click', () => light(l.dataset.part)));
    // the drawing is a pointer convenience; the quote's buttons stay the keyboard and screen-reader control
    parts.forEach((p) => p.addEventListener('click', () => {
      light(p.dataset.part);
      const line = lines.find((l) => l.dataset.part === p.dataset.part);
      if (lit && line && matchMedia('(max-width: 760px)').matches) line.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    }));
  }

  /* ---------- pains: each proof is stamped approved as it reaches the reader ---------- */
  const proofs = [...document.querySelectorAll('.proof')];
  if (proofs.length && !reduce && 'IntersectionObserver' in window) {
    // only what is still below the fold waits for its stamp, so nothing already on screen changes on its own
    const below = proofs.filter((p) => p.getBoundingClientRect().top > innerHeight * 0.9);
    if (below.length) {
      document.querySelector('.proofs').classList.add('is-live');
      proofs.filter((p) => !below.includes(p)).forEach((p) => p.classList.add('is-stamped'));
      const io = new IntersectionObserver((es) => es.forEach((e) => {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        setTimeout(() => e.target.classList.add('is-stamped'), 180 + (below.indexOf(e.target) % 3) * 160);
      }), { rootMargin: '0px 0px -25% 0px' });
      below.forEach((p) => io.observe(p));
    }
  }

  /* ---------- channels: tabs on the fascia ---------- */
  const tabs = [...document.querySelectorAll('.fascia [role="tab"]')];
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
      const rtl = document.dir === 'rtl' || document.documentElement.dir === 'rtl';
      const vertical = matchMedia('(max-width: 760px)').matches;
      const step = vertical ? { ArrowUp: -1, ArrowDown: 1 }[e.key] : { ArrowLeft: rtl ? 1 : -1, ArrowRight: rtl ? -1 : 1 }[e.key];
      if (step) { e.preventDefault(); show(tabs[(i + step + tabs.length) % tabs.length], true); }
      else if (e.key === 'Home') { e.preventDefault(); show(tabs[0], true); }
      else if (e.key === 'End') { e.preventDefault(); show(tabs[tabs.length - 1], true); }
    });
  });

  /* ---------- ready-made setup: the bars are drawn in order along the days, toward the opening ---------- */
  const ruler = document.querySelector('.ruler');
  if (ruler && !reduce && matchMedia('(min-width: 1024px)').matches) {
    const load = (src) => new Promise((ok, no) => { const el = document.createElement('script'); el.src = src; el.onload = ok; el.onerror = no; document.head.appendChild(el); });
    const bars = [...ruler.querySelectorAll('.ruler__bar')];
    const today = ruler.querySelector('.ruler__today');
    // the bars stay drawn if GSAP never arrives; they are only hidden once the tween is ready to run
    load('../../vendor/gsap.min.js').then(() => load('../../vendor/ScrollTrigger.min.js')).then(() => {
      gsap.registerPlugin(ScrollTrigger);
      const tl = gsap.timeline({ scrollTrigger: { trigger: ruler, start: 'top 70%', once: true } });
      tl.from(bars, { scaleX: 0, duration: 0.45, ease: 'power2.out', stagger: 0.16 })
        .from(today, { opacity: 0, y: 8, duration: 0.3, ease: 'power2.out' }, '-=0.2');
    }).catch(() => {});
  }
})();
