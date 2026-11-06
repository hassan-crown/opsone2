#!/usr/bin/env node
// Builds a sector page: node marketing/site/solutions/build.mjs kitchens
// Reads solutions/<sector>/data.json and writes solutions/<sector>/index.html as plain static HTML.
// No dependencies. The demo form is taken from the
// home page (../index.html) so it stays identical everywhere; icons come from ../assets/icons.svg; the header, footer, consent banner and «قطاعات قريبة» come from
// ../site/partials.mjs (shared with the home page); only the sector's own sections come from data.
// A sector can own the sections between the hero and the form: if solutions/<sector>/compose.mjs exists, its `sections`
// (an array of HTML) replace the template's six, and its optional `styles` / `scripts` are linked after the shared ones.
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { header, footer as siteFooter, related, consent as siteConsent } from '../site/partials.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const sector = process.argv[2];
if (!sector) { console.error('usage: node build.mjs <sector>'); process.exit(1); }
const d = JSON.parse(readFileSync(join(here, sector, 'data.json'), 'utf8'));
const home = readFileSync(join(here, '..', 'index.html'), 'utf8');
const ownFile = join(here, sector, 'compose.mjs');
const own = existsSync(ownFile) ? await import(pathToFileURL(ownFile).href) : null;
if (own && !(Array.isArray(own.sections) && own.sections.length)) throw new Error(`${sector}/compose.mjs must export sections`);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const links = (own?.styles || []).map((h) => `\n<link rel="stylesheet" href="${esc(h.replace(/\.css$/, '.min.css'))}">`).join('');
const scripts = (own?.scripts || []).map((h) => `\n<script src="${esc(h)}" defer></script>`).join('');
const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const icon = (id, cls = 'ic') => `<svg class="${cls}" aria-hidden="true"><use href="#${id}"/></svg>`;
const cut = (src, start, end) => { const i = src.indexOf(start); const j = src.indexOf(end, i); if (i < 0 || j < 0) throw new Error(`missing ${start}`); return src.slice(i, j + end.length); };
const root = '../../';

/* ---------- shared pieces from the home page ---------- */
const form = cut(home, '<form class="coupon"', '</form>')
  .replace('<option>منتجات مخصصة أو حسب الطلب</option>', '<option selected>منتجات مخصصة أو حسب الطلب</option>')
  .replace('<input type="hidden" name="utm_source">', `<input type="hidden" name="sector" value="${esc(d.slug)}"><input type="hidden" name="utm_source">`)
  .replace('<h2 class="head" id="demo-title">شاهد OpsOne كاملًا مع فريقنا</h2>', `<h2 class="head" id="demo-title">${esc(d.form.title)}</h2>`)
  .replace(/<p>اترك بياناتك[^<]*<\/p>/, `<p>${esc(d.form.lede)}</p><p class="coupon__sector">${icon(d.form.icon)}${esc(d.form.sectorLabel)}</p>`);
const consent = siteConsent({ root });
const footer = siteFooter({ root, slug: d.slug });

/* ---------- head ---------- */
const url = d.seo.canonical;
const snippet = d.seo.snippet || d.seo.description;
const ld = { '@context': 'https://schema.org', '@graph': [
  { '@type': 'SoftwareApplication', name: 'OpsOne', applicationCategory: 'BusinessApplication', operatingSystem: 'Web', inLanguage: 'ar', url, description: d.seo.description, image: d.seo.ogImage, publisher: { '@id': 'https://www.opsone.example/#organization' }, audience: { '@type': 'BusinessAudience', audienceType: d.seo.audience } },
  { '@type': 'BreadcrumbList', itemListElement: d.breadcrumb.map((b, i) => ({ '@type': 'ListItem', position: i + 1, name: b.name, item: b.url })) },
  { '@type': 'FAQPage', mainEntity: d.faq.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })) },
] };
const head = `<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>${esc(d.seo.title)}</title>
<meta name="description" content="${esc(snippet)}">
<meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="${esc(url)}">
<link rel="alternate" hreflang="ar" href="${esc(url)}">
<link rel="alternate" hreflang="x-default" href="${esc(url)}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="OpsOne">
<meta property="og:locale" content="ar_SA">
<meta property="og:title" content="${esc(d.seo.title)}">
<meta property="og:description" content="${esc(snippet)}">
<meta property="og:url" content="${esc(url)}">
<meta property="og:image" content="${esc(d.seo.ogImage)}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="${esc(d.seo.ogAlt)}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="${esc(d.seo.title)}">
<meta name="twitter:description" content="${esc(snippet)}">
<meta name="twitter:image" content="${esc(d.seo.ogImage)}">
<meta name="theme-color" content="#FFFFFF">
<link rel="icon" href="${root}assets/brand/opsone-mark.svg" type="image/svg+xml">
<link rel="icon" href="${root}assets/brand/favicon-32.png" type="image/png" sizes="32x32">
<link rel="apple-touch-icon" href="${root}assets/brand/apple-touch-icon.png" sizes="180x180">
<link rel="manifest" href="${root}site.webmanifest">
<link rel="preload" href="${root}assets/fonts/IBMPlexSansArabic-Bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="${root}assets/fonts/IBMPlexSansArabic-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" as="image" href="${esc(d.hero.image.src)}" imagesrcset="${esc(d.hero.image.srcset)}" imagesizes="(max-width: 760px) 100vw, 45vw" fetchpriority="high">
<link rel="stylesheet" href="${root}styles.min.css">
<link rel="stylesheet" href="../sector.min.css">${links}
<script>document.documentElement.classList.add('js');window.OPSONE_PAGE={sector:${JSON.stringify(d.slug)}};</script>
<script type="application/ld+json">${JSON.stringify(ld)}</script>
</head>`;

/* ---------- sections ---------- */
const bar = header({ root, slug: d.slug });

const crumbs = `<nav class="crumbs wrap" aria-label="مسار الصفحة"><ol>${d.breadcrumb.map((b, i) => i < d.breadcrumb.length - 1 ? `<li><a href="${esc(b.href)}">${esc(b.name)}</a></li>` : `<li aria-current="page">${esc(b.name)}</li>`).join('')}</ol></nav>`;

const live = d.stations.findIndex((s) => s.live);
const hero = `<section class="hero shero wrap" aria-labelledby="hero-title">
  <div class="shero__top">
    <div class="shero__copy">
      <h1 class="display" id="hero-title">${d.seo.h1 ? `<span class="shero__kicker">${esc(d.seo.h1)}</span>` : ''}${esc(d.hero.title1)}<br><span>${esc(d.hero.title2)}</span></h1>
      <p class="lead">${esc(d.hero.lede)}</p>
      <div class="acts">
        <a class="btn btn--go" href="#demo" data-cta="hero">احجز عرضًا توضيحيًا ${icon('i-arrow', 'ic ic--dir')}</a>
        <a class="btn btn--quiet" href="#order">${esc(d.hero.second)}</a>
      </div>
    </div>
    <figure class="shero__photo"><img src="${esc(d.hero.image.src)}" srcset="${esc(d.hero.image.srcset)}" sizes="(max-width: 760px) 100vw, 45vw" width="${d.hero.image.w}" height="${d.hero.image.h}" alt="${esc(d.hero.image.alt)}" fetchpriority="high" decoding="async"></figure>
  </div>
  <div class="route" id="route">
    <div class="stations stations--${d.stations.length}" role="group" aria-label="${esc(d.hero.lineLabel)}" id="stations" style="--n:${d.stations.length}">
      <span class="track" aria-hidden="true"><span class="track__fill" id="fill"></span></span>
${d.stations.map((s, i) => `      <button class="station" type="button" data-k="${s.key}" aria-pressed="${i === live}"><span class="dot">${icon(s.icon)}</span><span class="station__name">${esc(s.name)}</span>${i === live ? `<span class="station__tag nums" aria-hidden="true">${esc(d.order.ref)}</span>` : ''}<span class="sr">، ${i < live ? 'مكتملة' : i === live ? 'جارية الآن' : 'التالية'}</span></button>${i === live ? `
      <div class="screen is-open" id="screen"><span class="screen__notch" id="notch" aria-hidden="true"></span><div class="screen__card"><div class="screen__body" id="screen-body" aria-live="polite"></div></div></div>` : ''}`).join('\n')}
      <span class="ticket nums" id="ticket" aria-hidden="true">${esc(d.order.ref)}</span>
    </div>
  </div>
  <script type="application/json" id="station-data">${JSON.stringify({ live, stations: d.stations.map((s) => ({ key: s.key, screen: s.screen })) })}</script>
</section>`;

// the demo order: a top-view plan of the kitchen linked to the bill of quantities
const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const disc = o.discount || 0;
const net = sub - disc, vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (o.expectTotal && Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const paySum = o.payments.reduce((a, p) => a + p.pct, 0);
if (paySum !== 100) throw new Error(`payment plan sums to ${paySum}%`);
const order = `<section class="band korder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="order-title">${esc(o.title)}</h2><p class="lead">${esc(o.lede)}</p></div>
    <div class="korder__body">
      <figure class="kplan" aria-label="${esc(o.planLabel)}">
        ${o.planSvg}
        <figcaption class="kplan__cap">${esc(o.planCaption)}</figcaption>
      </figure>
      <div class="boq" data-ui>
        <div class="boq__head"><span><b>${esc(o.docTitle)}</b><span class="ref">${esc(o.quoteRef)}</span></span><span class="boq__to">${esc(o.client)}<small>${esc(o.ref)}، ${esc(o.branch)}</small></span></div>
        <ol class="boq__lines">
${o.lines.map((l) => `          <li><button class="boq__line" type="button" data-part="${l.part}" aria-pressed="false"><span class="boq__name">${esc(l.name)}</span><span class="boq__qty nums">${esc(l.qty)}</span><span class="boq__amt nums">${fmt(l.amount)}</span></button></li>`).join('\n')}
        </ol>
        <div class="boq__sum nums">
          <div><span>المجموع</span><span>${fmt(sub)}</span></div>
          ${disc ? `<div><span>${esc(o.discountLabel)}</span><span dir="ltr">−${fmt(disc)}</span></div>` : ''}
          <div><span>ضريبة القيمة المضافة 15%</span><span>${fmt(vat)}</span></div>
          <div class="boq__total"><span>الإجمالي شامل الضريبة</span><span>${fmt(total)} ر.س</span></div>
        </div>
        <div class="boq__plan">
          <b>${esc(o.planTitle)}</b>
          <ol class="payline">${o.payments.map((p) => `<li style="--w:${p.pct}"><span class="payline__pct nums">${p.pct}%</span><span class="payline__amt nums">${fmt(Math.round(total * p.pct) / 100)}</span><span class="payline__when">${esc(p.when)}</span></li>`).join('')}</ol>
        </div>
        <p class="boq__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;

const pains = `<section class="band kpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="pains-title">${esc(d.pains.title)}</h2><p class="lead">${esc(d.pains.lede)}</p></div>
    <ol class="swap">
${d.pains.items.map((p) => `      <li class="swap__item"><p class="swap__pain">${esc(p.pain)}</p><p class="swap__fix">${icon(p.icon)}<span>${esc(p.fix)}</span></p></li>`).join('\n')}
    </ol>
  </div>
</section>`;

const channels = `<section class="band kchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="channels-title">${esc(d.channels.title)}</h2><p class="lead">${esc(d.channels.lede)}</p></div>
    <ul class="panes">
${d.channels.items.map((c, i) => `      <li class="pane${i === 0 ? ' is-open' : ''}"><button class="pane__btn" type="button" aria-expanded="${i === 0}" aria-controls="pane-${c.key}"><img src="${esc(c.image.src)}"${c.image.srcset ? ` srcset="${esc(c.image.srcset)}" sizes="(max-width: 760px) 100vw, 60vw"` : ''} width="${c.image.w}" height="${c.image.h}" alt="${esc(c.image.alt)}" loading="lazy" decoding="async"><span class="pane__label">${icon(c.icon)}<b>${esc(c.title)}</b></span></button><div class="pane__body" id="pane-${c.key}"><p>${esc(c.text)}</p></div></li>`).join('\n')}
    </ul>
  </div>
</section>`;

const cfg = d.config;
const config = `<section class="band kcfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="setup-title">${esc(cfg.title)}</h2><p class="lead">${esc(cfg.lede)}</p></div>
    <div class="recipe">
      <div class="recipe__col">
        <h3>${icon('i-workflow')}${esc(cfg.stagesTitle)}</h3>
        <ol class="recipe__stages">${cfg.stages.map((s) => `<li><b>${esc(s.name)}</b><span class="nums">${esc(s.days)}</span><small>${esc(s.does)}</small></li>`).join('')}</ol>
      </div>
      <div class="recipe__col">
        <h3>${icon('i-wallet')}${esc(cfg.planTitle)}</h3>
        <div class="splits" role="group" aria-label="${esc(cfg.planTitle)}">${cfg.plans.map((p, i) => `<button class="splits__opt" type="button" aria-pressed="${i === 0}" data-plan="${i}"><bdi dir="ltr">${esc(p.name)}</bdi></button>`).join('')}</div>
        ${cfg.plans.map((p, i) => `<ol class="splitbar" data-plan="${i}"${i ? ' hidden' : ''}>${p.parts.map((x) => `<li style="--w:${x.pct}"><b class="nums">${x.pct}%</b><span>${esc(x.when)}</span></li>`).join('')}</ol>`).join('')}
        <p class="recipe__src">${esc(cfg.planNote)}</p>
      </div>
      <div class="recipe__col">
        <h3>${icon('i-file-text')}${esc(cfg.clausesTitle)}</h3>
        <ol class="recipe__clauses">${cfg.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
      </div>
    </div>
    <p class="recipe__edit">${icon('i-user-cog')}${esc(cfg.editable)}</p>
    ${cfg.bridge ? `<p class="recipe__bridge">${esc(cfg.bridge)}</p>` : ''}
  </div>
</section>`;

const roles = `<section class="band kroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="roles-title">${esc(d.roles.title)}</h2><p class="lead">${esc(d.roles.lede)}</p></div>
  </div>
  <div class="reel" tabindex="0" aria-label="${esc(d.roles.title)}">
    <ul class="reel__track">
${d.roles.items.map((r) => `      <li class="role"><span class="role__av" aria-hidden="true">${esc(r.letter)}</span><h3>${esc(r.name)}</h3><p class="role__who">${esc(r.who)}</p><ul>${r.does.map((x) => `<li>${icon('i-check')}${esc(x)}</li>`).join('')}</ul></li>`).join('\n')}
    </ul>
  </div>
</section>`;

const faq = `<section class="band kfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap kfaq__in">
    <div class="sec-head"><h2 class="head" id="faq-title">${esc(d.faqTitle)}</h2></div>
    <div class="qa">
${d.faq.map((f, i) => `      <details class="qa__item"${i === 0 ? ' open' : ''}><summary>${esc(f.q)}</summary><p>${esc(f.a)}</p></details>`).join('\n')}
    </div>
  </div>
</section>`;

const demo = `<section class="demo band" id="demo" aria-labelledby="demo-title">
  <div class="wrap">
${form}
  </div>
</section>`;

let html = `${head}
<body>
${bar}

<main id="main">
${crumbs}
${hero}
${(own ? own.sections : [order, pains, channels, config, roles, faq]).join('\n')}
${demo}
${related({ root, slug: d.slug })}
</main>

${footer}

${consent}
<script src="${root}config.js"></script>
<script src="${root}tags.js" defer></script>
<script src="${root}site.js" defer></script>
<script src="../sector.js" defer></script>${scripts}
</body>
</html>
`;
// icons come from one cached file, not an inline sprite
html = html.split('href="#i-').join(`href="${root}assets/icons.svg#i-`);
writeFileSync(join(here, sector, 'index.html'), html);
console.log(`built solutions/${sector}/index.html (${(html.length / 1024).toFixed(1)} kB); order total ${fmt(total)}, VAT ${fmt(vat)}`);
