// The site's shared pieces: header (with the «الحلول» menu), footer, the sector cards and the «قطاعات قريبة» block.
// One source for the home page (build-site.mjs writes them between <!-- @name --> markers in ../index.html) and for
// every sector page (solutions/build.mjs). The sector list, card copy and related sectors live in sectors.json.
// `root` is the path from the page to the site root: '' on the home page, '../../' on a sector page.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const site = JSON.parse(readFileSync(join(here, 'sectors.json'), 'utf8'));
export const sectors = site.sectors;

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const svg = (paths, cls = 'ic') => `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${paths}</svg>`;
const chevron = svg('<path d="m6 9 6 6 6-6"/>', 'ic ic--chev');
const burger = svg('<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>', 'ic ic--open');
const close = svg('<path d="M18 6 6 18"/><path d="m6 6 12 12"/>', 'ic ic--close');
const arrow = '<svg class="ic ic--dir" aria-hidden="true"><use href="#i-arrow"/></svg>';

// the home page's own sections, linked from every page
const product = [['fit', 'لمن OpsOne'], ['sales', 'مراحل العمل'], ['channels', 'قنوات البيع'], ['people', 'الصلاحيات'], ['modules', 'الأدوات']];
const home = (root) => root || './';
const hash = (root, id) => `${root}#${id}`;
const current = (slug, s) => (slug === s.slug ? ' aria-current="page"' : '');

const sectorLinks = (root, slug, cls) => sectors.map((s) => `<li><a class="${cls}" href="${root}solutions/${s.slug}/"${current(slug, s)}><b>${esc(s.name)}</b><span>${esc(s.hint)}</span></a></li>`).join('');

export function header({ root = '', slug = '' } = {}) {
  const links = product.filter(([id]) => id !== 'people').map(([id, label]) => `<li><a class="nav__link" href="${hash(root, id)}">${label}</a></li>`).join('');
  return `<a class="skip" href="#main">تخطَّ إلى المحتوى</a>
<header class="bar" id="top">
  <div class="wrap bar__in">
    <a class="bar__logo" href="${home(root)}" aria-label="OpsOne، الصفحة الرئيسية"><img src="${root}assets/brand/opsone-logo.svg" width="115" height="28" alt="OpsOne"></a>
    <nav class="nav" aria-label="الرئيسية">
      <ul class="nav__list">
        <li><details class="menu" data-menu><summary class="nav__link">الحلول ${chevron}</summary>
          <div class="menu__panel">
            <p class="menu__head">حلول حسب نشاطك</p>
            <ul class="menu__grid">${sectorLinks(root, slug, 'menu__item')}</ul>
            <a class="menu__all" href="${hash(root, 'solutions')}">كل الحلول في الصفحة الرئيسية ${arrow}</a>
          </div>
        </details></li>
        ${links}
      </ul>
    </nav>
    <a class="btn btn--go btn--small" href="#demo" data-cta="header">اطلب عرضًا تجريبيًا</a>
    <details class="drawer" data-menu>
      <summary class="drawer__btn" aria-label="القائمة">${burger}${close}</summary>
      <nav class="drawer__panel" aria-label="القائمة">
        <details class="drawer__group"><summary>الحلول حسب نشاطك ${chevron}</summary>
          <ul class="drawer__sectors">${sectorLinks(root, slug, 'drawer__item')}</ul>
        </details>
        <ul class="drawer__links">${product.map(([id, label]) => `<li><a href="${hash(root, id)}">${label}</a></li>`).join('')}</ul>
      </nav>
    </details>
  </div>
</header>`;
}

export function footer({ root = '', slug = '' } = {}) {
  return `<footer class="foot">
  <div class="wrap">
    <div class="foot__main">
      <div class="foot__brand">
        <a class="foot__logo" href="${home(root)}" aria-label="OpsOne، الصفحة الرئيسية"><img src="${root}assets/brand/opsone-logo.svg" width="148" height="36" loading="lazy" alt="OpsOne"></a>
        <p>نظام واحد للبيع والطلبات والتنفيذ والتحصيل.</p>
        <a class="btn btn--go" href="#demo" data-cta="footer">اطلب عرضًا تجريبيًا</a>
      </div>
      <nav class="foot__col foot__col--wide" aria-label="حلول حسب نشاطك"><h2>حلول حسب نشاطك</h2><ul>${sectors.map((s) => `<li><a href="${root}solutions/${s.slug}/"${current(slug, s)}>${esc(s.name)}</a></li>`).join('')}</ul></nav>
      <nav class="foot__col" aria-label="المنتج"><h2>المنتج</h2><ul><li><a href="${home(root)}">الصفحة الرئيسية</a></li><li><a href="${hash(root, 'benefits')}">لماذا OpsOne</a></li>${product.map(([id, label]) => `<li><a href="${hash(root, id)}">${label}</a></li>`).join('')}<li><a href="${hash(root, 'capabilities')}">كل الوحدات</a></li></ul></nav>
    </div>
    <div class="foot__base">
      <p class="nums">© 2026 OpsOne</p>
      <p><a href="#top">إلى أعلى الصفحة</a></p>
      <p><button class="foot__privacy" type="button" data-consent-open hidden>إعدادات الخصوصية</button></p>
      <p class="dev">تطوير <span data-developer lang="en">Available Solutions</span></p>
      <p>لقطات المنتج من OpsOne ببيانات تجريبية.</p>
    </div>
  </div>
</footer>`;
}

// one link card per sector (the home page's «حلول حسب نشاطك», and the related block on sector pages)
export const card = (root, s, indent = '      ') => `${indent}<li><a class="solution" href="${root}solutions/${s.slug}/">
${indent}  <img src="${root}solutions/${s.slug}/${s.image.src}" width="${s.image.w}" height="${s.image.h}" alt="" loading="lazy" decoding="async">
${indent}  <span class="solution__body"><b>${esc(s.name)}</b><span>${esc(s.line)}</span><span class="solution__go">اقرأ الصفحة ${arrow}</span></span>
${indent}</a></li>`;

export const solutionsGrid = (root = '') => `<ul class="solutions__grid">
${sectors.map((s) => card(root, s)).join('\n')}
    </ul>`;

export function related({ root, slug }) {
  const me = sectors.find((s) => s.slug === slug);
  if (!me) throw new Error(`${slug} is not in site/sectors.json`);
  return `<section class="band related" id="related" aria-labelledby="related-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="related-title">قطاعات قريبة</h2><p class="lead">أنشطة تعمل في البيوت والمشاريع نفسها، وتدير طلباتها في OpsOne بالطريقة نفسها.</p></div>
    <ul class="solutions__grid solutions__grid--related">
${me.related.map((r) => card(root, sectors.find((s) => s.slug === r))).join('\n')}
    </ul>
  </div>
</section>`;
}
