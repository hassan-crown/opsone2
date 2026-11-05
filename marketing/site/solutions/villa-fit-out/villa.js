/* OpsOne fit-out page: the schedule linked to the quote, the snag sheet closing, the channels merging, the settings
   tabs and the role matrix. Runs after ../sector.js (hero line, form, footer). CSS transitions only; every section is
   readable before this runs. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const once = (el, fn, t = 0.35) => {
    if (!el) return;
    if (!('IntersectionObserver' in window)) { fn(); return; }
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); fn(); } }, { threshold: t });
    io.observe(el);
  };
  // arm only what is still below the fold, so nothing the reader already sees disappears
  const below = (el) => el && el.getBoundingClientRect().top > innerHeight * 0.9;

  /* ---------- the demo project: a stage lights its group of quote lines, and back ---------- */
  const sched = document.querySelector('.sched');
  if (sched) {
    const rows = [...sched.querySelectorAll('.sched__row')];
    rows.forEach((r, i) => r.style.setProperty('--i', i));
    const btns = [...sched.querySelectorAll('button.sched__btn')];
    const groups = [...document.querySelectorAll('.ledger__group')];
    let pinned = null;
    const light = (k) => {
      rows.forEach((r) => r.classList.toggle('is-on', r.dataset.k === k));
      groups.forEach((g) => g.classList.toggle('is-on', g.dataset.k === k));
      btns.forEach((b) => b.setAttribute('aria-pressed', String(b.closest('.sched__row').dataset.k === k)));
    };
    btns.forEach((b) => {
      const k = b.closest('.sched__row').dataset.k;
      b.addEventListener('click', () => { pinned = pinned === k ? null : k; light(pinned); });
      if (fine) {
        b.addEventListener('pointerenter', () => light(k));
        b.addEventListener('pointerleave', () => light(pinned));
      }
    });
    groups.forEach((g) => {
      if (!fine) return;
      g.addEventListener('pointerenter', () => light(g.dataset.k));
      g.addEventListener('pointerleave', () => light(pinned));
    });
    // the schedule is laid once: each stage grows from its start, then its payment is pinned on
    if (!reduce && below(sched)) {
      sched.classList.add('is-armed');
      once(sched, () => requestAnimationFrame(() => { sched.classList.add('is-drawn'); sched.classList.remove('is-armed'); }));
    }
  }

  /* ---------- pains: the sheet closes its notes one after another, once ---------- */
  const sheet = document.querySelector('.sheet');
  if (sheet && !reduce && below(sheet)) {
    const snags = [...sheet.querySelectorAll('.snag')];
    const count = sheet.querySelector('[data-closed]');
    snags.forEach((s) => s.classList.remove('is-closed'));
    count.textContent = '0';
    once(sheet, () => snags.forEach((s, i) => setTimeout(() => { s.classList.add('is-closed'); count.textContent = String(i + 1); }, 260 + i * 170)), 0.25);
  }

  /* ---------- channels: three lines merge into the order, once ---------- */
  const merge = document.querySelector('.merge');
  if (merge && !reduce && below(merge.querySelector('.merge__lines')) && getComputedStyle(merge.querySelector('.merge__lines')).display !== 'none') {
    merge.querySelectorAll('.merge__lines path').forEach((p) => p.setAttribute('pathLength', '1'));
    merge.classList.add('is-armed');
    once(merge.querySelector('.merge__lines'), () => requestAnimationFrame(() => { merge.classList.add('is-drawn'); merge.classList.remove('is-armed'); }), 0.6);
  }

  /* ---------- ready-made setup: tabs (arrow keys, Home/End) and who carries out the work ---------- */
  const editor = document.querySelector('.editor');
  if (editor) {
    const tabs = [...editor.querySelectorAll('[role="tab"]')];
    const panels = tabs.map((t) => document.getElementById(t.getAttribute('aria-controls')));
    const show = (i, focus) => {
      tabs.forEach((t, j) => { t.setAttribute('aria-selected', String(i === j)); t.tabIndex = i === j ? 0 : -1; });
      panels.forEach((p, j) => { p.hidden = i !== j; p.classList.toggle('is-in', i === j); });
      if (focus) tabs[i].focus();
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => show(i));
      t.addEventListener('keydown', (e) => {
        const back = document.dir === 'rtl' ? 'ArrowRight' : 'ArrowLeft', fwd = document.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
        const n = { [fwd]: (i + 1) % tabs.length, [back]: (i - 1 + tabs.length) % tabs.length, Home: 0, End: tabs.length - 1 }[e.key];
        if (n != null) { e.preventDefault(); show(n, true); }
      });
    });
    panels.forEach((p, j) => { p.hidden = j !== 0; });
    const modes = [...editor.querySelectorAll('.mode__opt')];
    const setMode = (m) => { editor.dataset.mode = m; modes.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mode === m))); };
    modes.forEach((b) => b.addEventListener('click', () => {
      setMode(b.dataset.mode);
      if (!reduce && b.dataset.mode === 'factory' && document.body.animate) editor.querySelectorAll('.blk--factory').forEach((x, i) => x.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 240, delay: i * 50, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'backwards' }));
    }));
    setMode(modes[0].dataset.mode);
  }

  /* ---------- roles: pick a role, see its stages and its work ---------- */
  const matrix = document.querySelector('.matrix');
  if (matrix) {
    const whos = [...matrix.querySelectorAll('.who')];
    const items = [...matrix.querySelectorAll('.duty__item')];
    const pick = (i) => {
      whos.forEach((w, j) => { w.setAttribute('aria-pressed', String(i === j)); w.closest('tr').classList.toggle('is-on', i === j); });
      items.forEach((x, j) => x.classList.toggle('is-on', i === j));
    };
    whos.forEach((w, i) => w.addEventListener('click', () => pick(i)));
    pick(0);
  }
})();
