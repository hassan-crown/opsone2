#!/usr/bin/env node
// Builds the outdoor spaces page: node marketing/site-next/solutions/outdoor-spaces/compose.mjs
// Runs the shared ../build.mjs unchanged (head, SEO, JSON-LD, hero line, form, footer, consent), then replaces the
// sections between the hero and the form with this sector's own forms, and links outdoor.css / outdoor.js after the
// shared files. Once build.mjs accepts per-sector renderers, renderSections() moves there as-is (RESEARCH.md §7).
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const slug = 'outdoor-spaces';
execFileSync(process.execPath, [join(here, '..', 'build.mjs'), slug], { stdio: 'inherit' });
const d = JSON.parse(readFileSync(join(here, 'data.json'), 'utf8'));
const out = join(here, 'index.html');
let html = readFileSync(out, 'utf8');

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const icon = (id, cls = 'ic') => `<svg class="${cls}" aria-hidden="true"><use href="#${id}"/></svg>`;
const head = (id, title, lede) => `<div class="sec-head"><h2 class="head" id="${id}-title">${esc(title)}</h2>${lede ? `<p class="lead">${esc(lede)}</p>` : ''}</div>`;

/* ---------- the six kinds of work, drawn in line (a photograph in data.json replaces a drawing) ---------- */
const ART = {
  shade: '<path class="g" d="M8 104 H152"/><path d="M30 104 V46 M130 104 V58"/><path class="a" d="M18 44 Q80 30 142 58 L130 62 Q80 40 30 50 Z"/><path class="t" d="M44 92 h44 q6 0 8 -8 l4 -10 h-56 z"/><circle class="t" cx="54" cy="94" r="6"/><circle class="t" cx="84" cy="94" r="6"/>',
  screen: '<path class="g" d="M8 104 H152"/><rect x="14" y="70" width="132" height="34"/><path d="M14 82 H146 M14 94 H146"/><path class="a" d="M20 70 V24 M34 70 V24 M48 70 V24 M62 70 V24 M76 70 V24 M90 70 V24 M104 70 V24 M118 70 V24 M132 70 V24 M140 70 V24"/><path d="M14 24 H146"/>',
  pergola: '<path class="g" d="M8 104 H152"/><path d="M34 104 V40 M126 104 V40"/><path d="M18 40 H142 M18 48 H142"/><path class="a" d="M40 32 V40 M54 32 V40 M68 32 V40 M82 32 V40 M96 32 V40 M110 32 V40 M124 32 V40"/><path class="a" d="M24 32 H136"/><path class="t" d="M56 104 V86 H104 V104 M56 92 H104"/>',
  garden: '<path class="g" d="M8 104 H152"/><path class="a" d="M60 104 Q72 80 66 60 M96 104 Q86 82 92 62"/><circle cx="34" cy="78" r="18"/><path d="M34 96 V104"/><circle class="a" cx="122" cy="70" r="22"/><path d="M122 92 V104"/><path class="t" d="M14 104 q4 -8 8 0 q4 -8 8 0 M136 104 q4 -8 8 0"/>',
  pool: '<path class="g" d="M8 104 H152"/><path d="M20 60 H140 L150 88 H10 Z"/><path class="a" d="M24 72 q8 -5 16 0 t16 0 t16 0 t16 0 t16 0 t16 0 M20 80 q8 -5 16 0 t16 0 t16 0 t16 0 t16 0 t16 0 t16 0"/><path class="t" d="M120 60 V40 q0 -8 8 -8 M132 60 V40 q0 -8 8 -8 M120 48 H132"/>',
  seating: '<path class="g" d="M8 104 H152"/><path d="M14 96 V64 H30 V80 H110 V64 H126 V96 Z"/><path class="a" d="M30 80 V70 q0 -6 6 -6 H104 q6 0 6 6 V80"/><path d="M14 96 V104 M126 96 V104"/><path class="t" d="M134 84 H152 M138 84 V104 M148 84 V104"/>',
};
const s = d.scope;
const scope = `<section class="band oscope" id="scope" aria-labelledby="scope-title">
  <div class="wrap">
    ${head('scope', s.title, s.lede)}
    <ul class="kinds">
${s.items.map((k, i) => `      <li class="kind" style="--i:${i}"><figure class="kind__art">${k.image ? `<img src="${esc(k.image.src)}" width="${k.image.w}" height="${k.image.h}" alt="${esc(k.image.alt)}" loading="lazy" decoding="async">` : `<svg viewBox="0 0 160 112" aria-hidden="true">${ART[k.art]}</svg>`}</figure><h3>${esc(k.name)}</h3><p>${esc(k.text)}</p></li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- the demo order: a drawing sheet of the yard with numbered marks, beside the quote ---------- */
// one metre is 24 SVG units; the yard is 26 m by 17 m and its top-left corner sits at (8, 40)
const M = 24, X = (m) => 8 + m * M, Y = (m) => 40 + m * M;
const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - (o.discount || 0), vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const no = Object.fromEntries(o.lines.map((l) => [l.part, l.no]));
const slats = (x0, x1, y0, y1, step) => Array.from({ length: Math.floor((x1 - x0) / step) - 1 }, (_, i) => `M${x0 + (i + 1) * step} ${y0} V${y1}`).join(' ');
const pin = (part, x, y) => `<g class="pin" data-part="${part}"><circle cx="${x}" cy="${y}" r="15"/><text x="${x}" y="${y + 6}" text-anchor="middle">${no[part]}</text></g>`;
const lights = [[6.6, 11], [6.6, 13], [6.6, 15], [6.6, 16.6], [10.4, 11], [10.4, 13], [10.4, 15], [10.4, 16.6]];
const footings = [[11, 3], [15, 3], [11, 6.5], [15, 6.5], [6, 11.5], [6, 17]];
const plan = `<svg class="site" viewBox="0 0 640 500" role="img" aria-label="${esc(o.planLabel)}">
  <defs><pattern id="turf" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M2 8 l2 -4 l2 4" class="turf-tick"/></pattern></defs>
  <rect class="yard" x="${X(0)}" y="${Y(0)}" width="${26 * M}" height="${17 * M}"/>
  <rect class="house" x="${X(18)}" y="${Y(0)}" width="${8 * M}" height="${10 * M}"/><text class="lbl" x="${X(22)}" y="${Y(5) + 6}" text-anchor="middle">الفيلا</text>
  <path class="gate" d="M${X(0)} ${Y(17)} H${X(6.2)}"/>
  <g data-part="turf"><rect class="turf" x="${X(1)}" y="${Y(2)}" width="${9 * M}" height="${8 * M}" rx="10"/></g>
  <rect class="walk" x="${X(7)}" y="${Y(10)}" width="${3 * M}" height="${7 * M}"/>
  <g data-part="carport"><rect class="canopy" x="${X(0)}" y="${Y(11.5)}" width="${6 * M}" height="${5.5 * M}"/><path class="canopy-seam" d="${slats(X(0), X(6), Y(11.5), Y(17), 24)}"/></g>
  <g data-part="pergola"><rect class="frame" x="${X(11)}" y="${Y(3)}" width="${4 * M}" height="${3.5 * M}"/><path class="slat" d="${slats(X(11), X(15), Y(3), Y(6.5), 8)}"/></g>
  <g data-part="seating"><path class="sofa" d="M${X(11.4)} ${Y(3.4)} H${X(14.6)} V${Y(6.1)} H${X(14)} V${Y(4)} H${X(11.4)} Z"/></g>
  <g data-part="footing">${footings.map(([x, y]) => `<rect class="foot" x="${X(x) - 5}" y="${Y(y) - 5}" width="10" height="10"/>`).join('')}</g>
  <g data-part="lights">${lights.map(([x, y]) => `<circle class="lamp" cx="${X(x)}" cy="${Y(y)}" r="4.5"/>`).join('')}</g>
  <g data-part="screen"><path class="screen" d="M${X(0)} ${Y(0)} H${X(18)}"/></g>
  <g class="dims"><path d="M${X(0)} 20 H${X(18)} M${X(0)} 14 V26 M${X(18)} 14 V26"/><text x="${X(9)}" y="14" text-anchor="middle">18.00 م</text>
  <path d="M${X(0)} ${Y(17) + 18} H${X(6)} M${X(0)} ${Y(17) + 12} V${Y(17) + 24} M${X(6)} ${Y(17) + 12} V${Y(17) + 24}"/><text x="${X(3)}" y="${Y(17) + 40}" text-anchor="middle">6.00 م</text>
  <text x="${X(8.5)}" y="${Y(17) + 40}" text-anchor="middle">البوابة</text></g>
  ${pin('carport', X(3), Y(14.2))}${pin('screen', X(9), Y(0) + 20)}${pin('pergola', X(16.2), Y(3))}${pin('footing', X(7.2), Y(11.5))}${pin('turf', X(4), Y(5.6))}${pin('lights', X(8.5), Y(13.6))}${pin('seating', X(13), Y(5.3))}
</svg>`;
const measures = o.measures.map((m) => `<li><span>${esc(m.where)}، ${esc(m.what)}</span><b class="nums" dir="ltr">${esc(m.value)} ${esc(m.unit)}</b><span class="mk mk--${m.kind}">${m.kind === 'final' ? 'نهائي' : 'تقديري'}</span></li>`).join('');
const order = `<section class="band oorder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="oorder__body">
      <figure class="sheet">
        <div class="sheet__plan">${plan}</div>
        <figcaption class="sheet__block">
          <dl class="sheet__meta"><div><dt>العميل</dt><dd>${esc(o.client)}</dd></div><div><dt>الطلب</dt><dd class="nums">${esc(o.ref)}</dd></div><div><dt>الرفع</dt><dd>${esc(o.surveyor)}</dd></div><div><dt>تاريخ الرفع</dt><dd>${esc(o.surveyDate)}</dd></div></dl>
          <p class="sheet__cap">${esc(o.planCaption)}</p>
          <h3 class="sheet__h">${icon('i-ruler')}${esc(o.measureTitle)}</h3>
          <ul class="fieldbook">${measures}</ul>
        </figcaption>
      </figure>
      <div class="ledger" data-ui>
        <div class="ledger__head"><span><b>${esc(o.docTitle)}</b><span class="nums">${esc(o.quoteRef)}</span></span><span class="ledger__to">${esc(o.client)}<small>${esc(o.branch)}</small></span></div>
        <ol class="ledger__lines">
${o.lines.map((l) => `          <li><button class="ledger__line" type="button" data-part="${l.part}" aria-pressed="false"><span class="ledger__no nums" aria-hidden="true">${l.no}</span><span class="ledger__name">${esc(l.name)}<small class="nums">${esc(l.qty)} × ${esc(l.rate)}</small></span><span class="ledger__amt nums">${fmt(l.amount)}</span></button></li>`).join('\n')}
        </ol>
        <div class="ledger__sum nums">
          <div><span>المجموع</span><span>${fmt(sub)}</span></div>
          ${o.discount ? `<div><span>${esc(o.discountLabel)}</span><span dir="ltr">−${fmt(o.discount)}</span></div>` : ''}
          <div><span>ضريبة القيمة المضافة 15%</span><span>${fmt(vat)}</span></div>
          <div class="ledger__total"><span>الإجمالي شامل الضريبة</span><span>${fmt(total)} ر.س</span></div>
        </div>
        <div class="ledger__plan">
          <b>${esc(o.planTitle)}</b>
          <ol class="steps">${o.payments.map((p) => `<li><span class="steps__pct nums">${p.pct}%</span><span class="steps__amt nums">${fmt(Math.round(total * p.pct) / 100)}</span><span class="steps__when">${esc(p.when)}</span></li>`).join('')}</ol>
        </div>
        <p class="ledger__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;

/* ---------- pains: each one pinned to its place on the order's way ---------- */
const pains = `<section class="band opains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', d.pains.title, d.pains.lede)}
    <ol class="trail">
${d.pains.items.map((p) => `      <li class="trail__item"><span class="trail__phase">${esc(p.phase)}</span><div class="trail__card"><p class="trail__pain">${esc(p.pain)}</p><p class="trail__fix">${icon(p.icon)}<span>${esc(p.fix)}</span></p></div></li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- channels: tabs, each with a fragment of the screen it brings ---------- */
const c = d.channels;
const frag = {
  showroom: `<div class="frag"><p class="frag__h">${icon('i-inbox')} اهتمام جديد من زائر المعرض</p><ul class="swatches" aria-label="عينات القماش، والمختار منها زيتي">${c.showroomSwatches.map((w) => `<li${w.picked ? ' class="is-picked"' : ''}><span style="background:${w.hex}"></span>${esc(w.name)}</li>`).join('')}</ul><div class="frag__row"><span>مظلة سيارات، واللون في ملاحظة الاهتمام</span><span class="pill-s pill-s--done">موعد رفع الأحد</span></div></div>`,
  store: `<div class="frag"><p class="frag__h">منتجات جاهزة من المخزون</p><ul class="cart">${c.storeItems.map((it) => `<li><span>${esc(it.name)}</span><b class="nums">${esc(it.price)} ر.س</b></li>`).join('')}</ul><div class="frag__row"><span>${icon('i-shopping-bag')}رابط سلة لعميلك</span><span class="pill-s pill-s--live">دفع إلكتروني</span></div></div>`,
  field: `<div class="frag frag--phone"><p class="frag__h">زيارة حوش، من جوال البائع</p><ul class="cart"><li><span>مظلة السيارات، العرض</span><b class="nums" dir="ltr">6.00 م</b></li><li><span>السور الشمالي، الطول</span><b class="nums" dir="ltr">18.00 م</b></li><li><span>صور الموقع</span><b class="nums">9</b></li></ul><div class="frag__row"><span>${icon('i-signature')}العرض ورابط توقيع العقد</span><span class="pill-s pill-s--done">أُرسل</span></div></div>`,
};
const channels = `<section class="band ochan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', c.title, c.lede)}
    <div class="tabs">
      <div class="tabs__list" role="tablist" aria-label="${esc(c.title)}">${c.items.map((it, i) => `<button class="tabs__tab" type="button" role="tab" id="tab-${it.key}" aria-controls="panel-${it.key}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${icon(it.icon)}<span>${esc(it.title)}</span></button>`).join('')}</div>
${c.items.map((it, i) => `      <div class="tabs__panel" role="tabpanel" id="panel-${it.key}" aria-labelledby="tab-${it.key}"${i ? ' hidden' : ''}><p>${esc(it.text)}</p>${frag[it.key]}</div>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- ready-made setup: the flow as bars on a scale of days, with the payment plan pinned on it ---------- */
const cfg = d.config;
const totalDays = cfg.stages.reduce((a, x) => a + x.days, 0);
let at = 0;
const bars = cfg.stages.map((x) => { const r = { ...x, start: at }; at += x.days; return r; });
// Arabic counts: «يومان» stands alone, 3–10 take «أيام», 11 and up take «يومًا»
const days = (n) => (n === 1 ? 'يوم' : n === 2 ? 'يومان' : `${n} ${n <= 10 ? 'أيام' : 'يومًا'}`);
const startOf = (name) => bars.find((b) => b.name === name).start;
const marks = { agreement: startOf('الاتفاق والدفعة الأولى') + cfg.stages.find((x) => x.name === 'الاتفاق والدفعة الأولى').days, install: startOf('التركيب والتسليم'), end: totalDays };
// a pin closer than eight days to the one before it drops to a second row, so the labels never overlap
const planPins = cfg.plans.map((p) => p.parts.map((x) => ({ pct: x.pct, when: x.when, day: marks[x.at] })).map((x, k, a) => ({ ...x, row: k && x.day - a[k - 1].day < 8 ? 1 : 0 })));
const pinsHtml = (i) => planPins[i].map((x) => `<li style="--at:${x.day};--row:${x.row}"><b class="nums">${x.pct}%</b><span>${esc(x.when)}</span></li>`).join('');
const setup = `<section class="band ocfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', cfg.title, cfg.lede)}
    <div class="gantt" style="--t:${totalDays}">
      <div class="gantt__top"><h3>${icon('i-workflow')}${esc(cfg.stagesTitle)}</h3>
        <div class="plans" role="group" aria-label="${esc(cfg.planTitle)}"><span class="plans__lbl">${esc(cfg.planTitle)}</span>${cfg.plans.map((p, i) => `<button class="plans__opt" type="button" aria-pressed="${i === 0}" data-plan="${i}"><bdi dir="ltr">${esc(p.name)}</bdi></button>`).join('')}</div>
      </div>
      <ol class="gantt__rows">
${bars.map((b) => `        <li class="gantt__row${b.added ? ' is-added' : ''}" style="--s:${b.start};--d:${b.days}"><span class="gantt__name"><b>${esc(b.name)}</b><small>${esc(b.does)}</small></span><span class="gantt__lane"><span class="gantt__bar"><span class="nums">${days(b.days)}</span></span></span></li>`).join('\n')}
      </ol>
      <div class="gantt__axis"><span class="gantt__axis-lbl">الدفعات على المسار</span><ol class="gantt__pins" id="plan-pins" aria-live="polite">${pinsHtml(0)}</ol></div>
      <p class="gantt__note">${esc(cfg.addedNote)} ${esc(cfg.planNote)}</p>
      <script type="application/json" id="plan-data">${JSON.stringify(planPins)}</script>
    </div>
    <div class="clauses">
      <h3>${icon('i-file-text')}${esc(cfg.clausesTitle)}</h3>
      <ol>${cfg.clauses.map((x) => `<li>${esc(x)}</li>`).join('')}</ol>
    </div>
    <p class="recipe__edit">${icon('i-user-cog')}${esc(cfg.editable)}</p>
  </div>
</section>`;

/* ---------- roles: a grid of people, each with what they do and what they do not see ---------- */
const roles = `<section class="band oroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', d.roles.title, d.roles.lede)}
    <ul class="people">
${d.roles.items.map((r) => `      <li class="person"><span class="person__av" aria-hidden="true">${esc(r.letter)}</span><h3>${esc(r.name)}</h3><p class="person__who">${esc(r.who)}</p><ul>${r.does.map((x) => `<li>${icon('i-check')}${esc(x)}</li>`).join('')}</ul>${r.hides.length ? `<p class="person__hide">${icon('i-eye-off')}لا يرى ${r.hides.map(esc).join('، ')}</p>` : ''}</li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- FAQ: three short columns by topic ---------- */
const faq = `<section class="band ofaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle)}
    <div class="topics">
${d.faqGroups.map((g) => `      <div class="topic"><h3>${esc(g.name)}</h3>${d.faq.filter((f) => f.group === g.key).map((f) => `<details class="topic__q"><summary>${esc(f.q)}</summary><p>${esc(f.a)}</p></details>`).join('')}</div>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- splice ---------- */
const startTag = '<section class="band korder"';
const endTag = '<section class="demo band"';
const i = html.indexOf(startTag), j = html.indexOf(endTag);
if (i < 0 || j < 0) throw new Error('section markers not found in build output');
html = `${html.slice(0, i)}${scope}\n${order}\n${pains}\n${channels}\n${setup}\n${roles}\n${faq}\n${html.slice(j)}`;
html = html.replace('<link rel="stylesheet" href="../sector.css">', '<link rel="stylesheet" href="../sector.css">\n<link rel="stylesheet" href="outdoor.css">')
  .replace('<script src="../sector.js" defer></script>', '<script src="../sector.js" defer></script>\n<script src="outdoor.js" defer></script>');
if (!html.includes('outdoor.css') || !html.includes('outdoor.js')) throw new Error('asset links not added');
writeFileSync(out, html);
console.log(`composed solutions/${slug}/index.html (${(html.length / 1024).toFixed(1)} kB); ${totalDays} days in the flow`);
