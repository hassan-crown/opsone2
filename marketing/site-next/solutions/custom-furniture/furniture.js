/* OpsOne custom-furniture page: the material board lights its quote lines, the hang tags turn over, the wardrobe
   doors open on a channel, the ruler switches between flows and payment plans, and the role lens shows what each role
   works on. Every section is readable without this file; CSS transitions only. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- the material board and the quote ---------- */
  const spec = document.querySelector('.spec');
  const sws = [...document.querySelectorAll('.sw')];
  const lines = [...document.querySelectorAll('.spec__line')];
  const live = document.getElementById('board-live');
  if (spec && sws.length) {
    let pinned = null;
    const light = (k, announce) => {
      spec.classList.toggle('is-filtered', !!k);
      let n = 0;
      lines.forEach((l) => { const on = !!k && l.dataset.mats.split(' ').includes(k); l.classList.toggle('is-lit', on); if (on) n++; });
      document.querySelectorAll('.sched__group').forEach((g) => g.classList.toggle('is-lit', !!k && g.dataset.mats.split(' ').includes(k)));
      if (announce && live) { const s = sws.find((b) => b.dataset.mat === k); live.textContent = k ? `${s.querySelector('.sw__name').textContent}: ${n === 1 ? 'بند واحد' : n === 2 ? 'بندان' : n + ' بنود'} في عرض السعر` : 'كل البنود'; }
    };
    sws.forEach((b) => {
      b.addEventListener('click', () => {
        pinned = pinned === b.dataset.mat ? null : b.dataset.mat;
        sws.forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.mat === pinned)));
        light(pinned, true);
      });
      if (fine) {
        b.addEventListener('pointerenter', () => { if (!pinned) light(b.dataset.mat); });
        b.addEventListener('pointerleave', () => { if (!pinned) light(null); });
      }
    });
    if (fine) lines.forEach((l) => {
      l.addEventListener('pointerenter', () => { if (!pinned) sws.forEach((b) => b.classList.toggle('is-hint', l.dataset.mats.split(' ').includes(b.dataset.mat))); });
      l.addEventListener('pointerleave', () => sws.forEach((b) => b.classList.remove('is-hint')));
    });
  }

  /* ---------- hang tags: one turns over at a time on demand, or all at once ---------- */
  const tags = [...document.querySelectorAll('.tag')];
  const all = document.querySelector('.tags__all');
  const setTag = (t, on) => {
    t.classList.toggle('is-flipped', on);
    t.querySelector('.tag__front .tag__flip').setAttribute('aria-expanded', String(on));
    t.querySelector('.tag__front').toggleAttribute('inert', on);
    t.querySelector('.tag__back').toggleAttribute('inert', !on);
  };
  tags.forEach((t) => {
    setTag(t, false);
    t.querySelector('.tag__front .tag__flip').addEventListener('click', () => { setTag(t, true); t.querySelector('.tag__flip--back').focus({ preventScroll: true }); });
    t.querySelector('.tag__flip--back').addEventListener('click', () => { setTag(t, false); t.querySelector('.tag__front .tag__flip').focus({ preventScroll: true }); });
  });
  if (all) all.addEventListener('click', () => {
    const on = all.getAttribute('aria-pressed') !== 'true';
    all.setAttribute('aria-pressed', String(on));
    all.textContent = on ? 'أعد البطاقات كلها' : 'اقلب البطاقات كلها';
    tags.forEach((t, i) => (reduce ? setTag(t, on) : setTimeout(() => setTag(t, on), i * 45)));
  });

  /* ---------- wardrobe doors: each opens on its channel ---------- */
  document.querySelectorAll('.robe__bay').forEach((bay) => {
    const door = bay.querySelector('.robe__door'), inside = bay.querySelector('.robe__inside'), hint = door.querySelector('small');
    const drawer = matchMedia('(max-width: 1100px)');
    const set = (on) => {
      bay.classList.toggle('is-open', on);
      door.setAttribute('aria-expanded', String(on));
      inside.toggleAttribute('inert', !on);
      hint.textContent = drawer.matches ? (on ? 'أغلق الدرج' : 'افتح الدرج') : (on ? 'أغلق الباب' : 'افتح الباب');
    };
    set(bay.classList.contains('is-open'));
    door.addEventListener('click', () => set(!bay.classList.contains('is-open')));
    drawer.addEventListener('change', () => set(bay.classList.contains('is-open')));
  });

  /* ---------- the ruler: flow and payment plan ---------- */
  const ruler = document.querySelector('.ruler');
  if (ruler) {
    let plan = '0';
    const tapes = [...ruler.querySelectorAll('.tape')];
    const showPins = () => ruler.querySelectorAll('.tape__pins').forEach((p) => { p.hidden = p.dataset.plan !== plan; });
    const draw = (t) => { if (reduce) return; t.classList.remove('is-drawn'); void t.offsetWidth; t.classList.add('is-drawn'); };
    tapes.forEach((t) => t.querySelectorAll('.tape__seg').forEach((s, i) => s.style.setProperty('--i', i)));
    ruler.querySelectorAll('[data-track].seg__opt').forEach((b) => b.addEventListener('click', () => {
      ruler.querySelectorAll('[data-track].seg__opt').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      tapes.forEach((t) => { t.hidden = t.dataset.track !== b.dataset.track; if (!t.hidden) draw(t); });
    }));
    ruler.querySelectorAll('[data-plan].seg__opt').forEach((b) => b.addEventListener('click', () => {
      ruler.querySelectorAll('[data-plan].seg__opt').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      plan = b.dataset.plan; showPins();
    }));
    if (!reduce && 'IntersectionObserver' in window) {
      const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); draw(tapes.find((t) => !t.hidden)); } }, { threshold: 0.5 });
      io.observe(ruler);
    }
  }

  /* ---------- the role lens ---------- */
  const roleBtns = [...document.querySelectorAll('.lens__role')];
  const who = document.getElementById('lens-who');
  roleBtns.forEach((b) => b.addEventListener('click', () => {
    roleBtns.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    document.querySelectorAll('.lens__view').forEach((v) => { v.hidden = v.dataset.role !== b.dataset.role; });
    if (who) who.textContent = b.querySelector('b').textContent;
  }));
})();
