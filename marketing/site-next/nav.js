/* OpsOne site header: the «الحلول» menu and the phone drawer are <details>, so they open and close without
   JavaScript. This file only adds what <details> lacks: one menu open at a time, Escape closes and returns focus,
   a click outside or on a link inside closes. */
(() => {
  const menus = [...document.querySelectorAll('details[data-menu]')];
  if (!menus.length) return;
  const shut = (m, focus) => { if (!m.open) return; m.open = false; if (focus) m.querySelector('summary').focus(); };
  menus.forEach((m) => {
    m.addEventListener('toggle', () => { if (m.open) menus.forEach((o) => { if (o !== m) shut(o); }); });
    // a link inside the menu goes somewhere: on this page (a #hash) the menu would otherwise stay open over the content
    m.addEventListener('click', (e) => { if (e.target.closest('a')) shut(m); });
    // Tab out of an open panel closes it, so it never covers the element that now has focus
    m.addEventListener('focusout', (e) => { if (e.relatedTarget && !m.contains(e.relatedTarget)) shut(m); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const open = menus.find((m) => m.open && m.contains(document.activeElement)) || menus.find((m) => m.open);
    if (open) { e.preventDefault(); shut(open, true); }
  });
  // pointerdown, not click: iOS Safari sends no click for taps on non-interactive content
  document.addEventListener('pointerdown', (e) => { menus.forEach((m) => { if (m.open && !m.contains(e.target)) shut(m); }); });
  // the drawer belongs to narrow screens: close it if the window grows past it
  const wide = matchMedia('(min-width: 1101px)');
  const onWide = () => { if (wide.matches) menus.filter((m) => m.classList.contains('drawer')).forEach((m) => shut(m)); };
  if (wide.addEventListener) wide.addEventListener('change', onWide);
})();
