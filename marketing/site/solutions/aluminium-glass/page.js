/* Aluminium & glass page: the schedule filter, the pains as tabs on wide screens, and the factory-mode switch.
   Everything is visible without JS; these only add the choices. CSS transitions and the Web Animations API only. */
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';

  /* ---------- the schedule of openings: show one kind of item and its subtotal ---------- */
  const sheet = document.querySelector('.sheet');
  const sumsEl = document.getElementById('sheet-sums');
  if (sheet && sumsEl) {
    const sums = JSON.parse(sumsEl.textContent);
    const opts = [...sheet.querySelectorAll('.seg__opt')];
    const parts = [...sheet.querySelectorAll('[data-part]')];
    const label = document.getElementById('sum-label'), val = document.getElementById('sum-val');
    opts.forEach((b) => b.addEventListener('click', () => {
      const k = b.dataset.filter;
      opts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      sheet.classList.toggle('is-filtered', k !== 'all');
      parts.forEach((p) => p.classList.toggle('is-in', p.dataset.part === k));
      label.textContent = `مجموع ${sums[k].label}`;
      val.textContent = sums[k].sum;
    }));
  }

  /* ---------- pains: tabs from 900px, a plain list of answers below it ---------- */
  const diag = document.querySelector('.diag');
  if (diag) {
    const tabs = [...diag.querySelectorAll('.diag__tab')];
    const panels = [...diag.querySelectorAll('.diag__panel')];
    const wide = matchMedia('(min-width: 900px)');
    let on = 0;
    const show = (i, focus, swap = false) => {
      on = i;
      tabs.forEach((t, j) => { t.setAttribute('aria-selected', String(j === i)); t.tabIndex = j === i ? 0 : -1; });
      panels.forEach((p, j) => { p.classList.toggle('is-on', j === i); p.classList.toggle('is-swap', j === i && swap); });
      if (focus) tabs[i].focus();
    };
    // below 900px the tabs are hidden and each answer carries its own heading, so the tab roles come off
    const mode = () => {
      panels.forEach((p) => { if (wide.matches) { p.setAttribute('role', 'tabpanel'); p.tabIndex = 0; } else { p.removeAttribute('role'); p.removeAttribute('tabindex'); } });
      if (wide.matches) show(on, false);
    };
    tabs.forEach((t, i) => {
      t.addEventListener('click', () => show(i, false, true));
      t.addEventListener('keydown', (e) => {
        const step = { ArrowDown: 1, ArrowUp: -1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step === undefined) return;
        e.preventDefault();
        show((i + step + tabs.length) % tabs.length, true, true);
      });
    });
    wide.addEventListener('change', mode);
    mode();
  }

  /* ---------- ready-made setup: an external factory or your own ---------- */
  const gantt = document.querySelector('.gantt');
  if (gantt) {
    const opts = [...gantt.querySelectorAll('.seg__opt')];
    const lists = [...gantt.querySelectorAll('.gantt__rows')];
    const total = document.getElementById('gantt-total'), own = document.getElementById('gantt-own');
    opts.forEach((b) => b.addEventListener('click', () => {
      if (b.getAttribute('aria-pressed') === 'true') return;
      const k = b.dataset.mode;
      opts.forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      lists.forEach((l) => { l.hidden = l.dataset.mode !== k; });
      total.textContent = `المجموع ${total.dataset[k]} يومًا`;
      own.hidden = k !== 'own';
      if (reduce || !document.body.animate) return;
      // the bars are laid out again from the start of the schedule, one after another
      gantt.querySelectorAll(`.gantt__rows[data-mode="${k}"] .gantt__bar`).forEach((bar, i) => bar.animate(
        [{ transform: 'scaleX(0.6)', opacity: 0 }, { transform: 'none', opacity: 1 }],
        { duration: 260, delay: i * 35, easing: EASE_OUT, fill: 'backwards' },
      ));
    }));
  }
})();
