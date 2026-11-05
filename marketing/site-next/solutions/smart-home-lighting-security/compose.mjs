// The smart home, lighting and security page's own sections. Built by the shared builder: node marketing/site-next/solutions/build.mjs smart-home-lighting-security
// build.mjs writes the head, SEO, hero line, form, footer and consent (and checks the order total and the payment plan), then
// imports this file and puts `sections` in place of the template's six middle sections, linking `styles` and `scripts`
// after ../sector.css and ../sector.js.
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const d = JSON.parse(readFileSync(join(here, 'data.json'), 'utf8'));

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const icon = (id, cls = 'ic') => `<svg class="${cls}" aria-hidden="true"><use href="#${id}"/></svg>`;
const r2 = (n) => Math.round(n * 100) / 100;
const head = (id, title, lede) => `<div class="sec-head"><h2 class="head" id="${id}-title">${esc(title)}</h2>${lede ? `<p class="lead">${esc(lede)}</p>` : ''}</div>`;

/* ---------- the demo order: the villa's device schedule, grouped by system, linked to the ground-floor plan ---------- */
const o = d.order;
for (const l of o.lines) if (l.count * l.price !== l.amount) throw new Error(`${l.code}: ${l.count} × ${l.price} ≠ ${l.amount}`);
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - o.discount, vat = r2(net * 0.15), total = r2(net + vat);
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const byCode = Object.fromEntries(o.lines.map((l) => [l.code, l]));
const SYS = { light: 'الإنارة', control: 'التحكم والشبكة', cctv: 'المراقبة', security: 'الأمن والدخول', works: 'التمديد والتركيب' };

// one device on the plan; its shape says which system it belongs to (the legend repeats the shapes)
const mark = (p) => {
  const l = byCode[p.code];
  if (!l) throw new Error(`plan point ${p.code} has no line`);
  const at = `data-code="${l.code}" data-sys="${l.sys}"`;
  const { x, y } = p;
  switch (l.code) {
    case 'L01': return `<circle class="pt pt--ring" ${at} cx="${x}" cy="${y}" r="7"/>`;
    case 'L02': return `<circle class="pt pt--dot" ${at} cx="${x}" cy="${y}" r="3.5"/>`;
    case 'L03': return `<rect class="pt pt--sq" ${at} x="${x - 5}" y="${y - 5}" width="10" height="10" rx="2"/>`;
    case 'L04': case 'L06': return `<rect class="pt pt--box" ${at} x="${x - 9}" y="${y - 6}" width="18" height="12" rx="2"/>`;
    case 'L10': return `<g class="pt pt--ap" ${at}><circle cx="${x}" cy="${y}" r="8"/><circle cx="${x}" cy="${y}" r="2.5"/></g>`;
    case 'L05': {
      const a = (p.a * Math.PI) / 180, s = 0.42, R = 54;
      const pt = (t) => `${(x + R * Math.cos(t)).toFixed(1)} ${(y + R * Math.sin(t)).toFixed(1)}`;
      return `<g class="pt pt--cam" ${at}><path class="fov" d="M${x} ${y} L${pt(a - s)} A${R} ${R} 0 0 1 ${pt(a + s)} Z"/><circle cx="${x}" cy="${y}" r="5.5"/></g>`;
    }
    case 'L07': return `<rect class="pt pt--box" ${at} x="${x - 7}" y="${y - 7}" width="14" height="14" rx="3"/>`;
    case 'L08': return `<rect class="pt pt--lock" ${at} x="${x - 5}" y="${y - 5}" width="10" height="10" transform="rotate(45 ${x} ${y})"/>`;
    case 'L09': return `<rect class="pt pt--bar" ${at} x="${x - 6}" y="${y - 3}" width="12" height="6" rx="1"/>`;
    default: throw new Error(`no mark for ${l.code}`);
  }
};
const pl = o.plan;
const planSvg = `<svg viewBox="0 0 520 380" role="img" aria-label="${esc(o.planLabel)}">
          <rect class="fence" x="20" y="20" width="480" height="340"/>
          <path class="gate" d="M270 20 H300"/>
          ${pl.rooms.map((r) => `<rect class="room" x="${r.x}" y="${r.y}" width="${r.w}" height="${r.h}"/>`).join('')}
          <path class="door" d="M256 70 H284"/>
          ${pl.rooms.map((r) => `<text class="rlbl" x="${r.x + r.w / 2}" y="${r.y + r.h - 12}" text-anchor="middle">${esc(r.name)}</text>`).join('')}
          <text class="street" x="400" y="13" text-anchor="middle">الشارع</text>
          ${pl.points.map(mark).join('')}
        </svg>`;
const legend = o.filters.filter((f) => f.key !== 'all').map((f) => `<li data-sys="${f.key}"><span class="lg lg--${f.key}" aria-hidden="true"></span>${esc(f.label)}<b class="nums">${pl.points.filter((p) => byCode[p.code].sys === f.key).length}</b></li>`).join('');
const groups = Object.keys(SYS).map((sys) => {
  const ls = o.lines.filter((l) => l.sys === sys);
  return `<li class="grp" data-sys="${sys}">
            <h3 class="grp__head"><span>${esc(SYS[sys])}</span><b class="nums">${fmt(ls.reduce((a, l) => a + l.amount, 0))}</b></h3>
            <ol>${ls.map((l) => `<li><button class="dev" type="button" data-code="${l.code}" data-sys="${l.sys}" aria-pressed="false"${l.sys === 'works' ? ' disabled' : ''}><span class="dev__code nums">${l.code}</span><span class="dev__name">${esc(l.name)}</span><span class="dev__calc nums">${l.count} × ${fmt(l.price)}</span><span class="dev__amt nums">${fmt(l.amount)}</span></button></li>`).join('')}</ol>
          </li>`;
}).join('\n          ');
const sums = Object.fromEntries(o.filters.map((f) => [f.key, { label: f.label, sum: fmt(f.key === 'all' ? sub : o.lines.filter((l) => l.sys === f.key).reduce((a, l) => a + l.amount, 0)) }]));
const order = `<section class="band shorder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="sched" data-ui data-show="all">
      <div class="sched__bar">
        <div class="seg" role="group" aria-label="${esc(o.filterLabel)}">${o.filters.map((f, i) => `<button class="seg__opt" type="button" data-filter="${f.key}" aria-pressed="${i === 0}">${esc(f.label)}</button>`).join('')}</div>
        <p class="sched__sum" aria-live="polite"><span id="sum-label">مجموع ${esc(o.filters[0].label)}</span><b class="nums" id="sum-val">${fmt(sub)}</b></p>
      </div>
      <script type="application/json" id="sched-sums">${JSON.stringify(sums)}</script>
      <div class="sched__body">
        <figure class="vplan">
          ${planSvg}
          <ul class="vplan__legend">${legend}</ul>
          <figcaption class="vplan__cap">${esc(o.planCaption)}</figcaption>
        </figure>
        <div class="devs">
          <div class="devs__head"><span><b>${esc(o.docTitle)}</b><span class="nums">${esc(o.quoteRef)}</span></span><span class="devs__to">${esc(o.client)}<small><span class="nums">${esc(o.ref)}</span>، ${esc(o.branch)}</small></span></div>
          <ol class="grps">
          ${groups}
          </ol>
          <div class="devs__sum nums">
            <div><span>المجموع</span><span>${fmt(sub)}</span></div>
            <div><span>${esc(o.discountLabel)}</span><span dir="ltr">−${fmt(o.discount)}</span></div>
            <div><span>ضريبة القيمة المضافة 15%</span><span>${fmt(vat)}</span></div>
            <div class="devs__total"><span>الإجمالي شامل الضريبة</span><span>${fmt(total)} ر.س</span></div>
          </div>
          <div class="devs__plan">
            <b>${esc(o.planTitle)}</b>
            <ol class="payline">${o.payments.map((p) => `<li style="--w:${p.pct}"><span class="payline__pct nums">${p.pct}%</span><span class="payline__amt nums">${fmt(Math.round(total * p.pct) / 100)}</span><span class="payline__when">${esc(p.when)}</span></li>`).join('')}</ol>
          </div>
          <p class="devs__note">${esc(o.note)}</p>
        </div>
      </div>
    </div>
  </div>
</section>`;

/* ---------- pains: the heading stays beside a column of numbered cases ---------- */
const pains = `<section class="band shpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap shpains__in">
    ${head('pains', d.pains.title, d.pains.lede)}
    <ol class="cases">
${d.pains.items.map((p, i) => `      <li class="case"><span class="case__n nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span><p class="case__pain">${esc(p.pain)}</p><p class="case__fix">${icon(p.icon)}<span>${esc(p.fix)}</span></p></li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- channels: three lanes, photo and text alternating sides ---------- */
// a lane with `art` gets a drawing instead of its photo; delete `art` in data.json to show `image` (build.mjs requires one)
const ART = {
  keypad: (alt) => {
    const keys = ['ترحيب', 'سهرة', 'نوم', 'خروج'];
    return `<svg class="art" viewBox="0 0 600 400" direction="ltr" role="img" aria-label="${esc(alt)}"><rect class="wall" x="0" y="0" width="600" height="400"/><circle class="glow" cx="300" cy="200" r="190"/><rect class="panel" x="215" y="58" width="170" height="284" rx="18"/>${keys.map((k, i) => { const y = 78 + i * 64, on = i === 1; return `<rect class="key${on ? ' key--on' : ''}" x="233" y="${y}" width="134" height="52" rx="10"/><text class="klbl" x="${on ? 290 : 300}" y="${y + 32}" text-anchor="middle">${k}</text>${on ? `<circle class="led led--on" cx="345" cy="${y + 26}" r="5"/>` : ''}`; }).join('')}</svg>`;
  },
  shell: (alt) => {
    const bricks = [];
    for (let r = 0; r < 10; r++) for (let c = -1; c < 8; c++) bricks.push(`<rect class="brick" x="${c * 84 + (r % 2) * 42}" y="${28 + r * 34}" width="84" height="34"/>`);
    const box = (x, y, data) => `<rect class="box${data ? ' box--data' : ''}" x="${x - 18}" y="${y - 18}" width="36" height="36" rx="4"/>`;
    return `<svg class="art" viewBox="0 0 600 400" direction="ltr" role="img" aria-label="${esc(alt)}"><rect class="wall" x="0" y="0" width="600" height="400"/>${bricks.join('')}<rect class="slab" x="0" y="0" width="600" height="28"/><rect class="slab" x="0" y="372" width="600" height="28"/>
<path class="duct" d="M110 28 V200"/><path class="duct" d="M230 28 V150 H300 V300"/><path class="duct duct--data" d="M470 28 V92"/><path class="duct duct--data" d="M520 372 V290 H420 V250"/>${box(110, 218, 0)}${box(300, 318, 0)}${box(470, 110, 1)}${box(420, 232, 1)}
<g class="alb"><text x="136" y="223">مفتاح</text><text x="326" y="323">مقبس</text><text x="444" y="115" text-anchor="end">كاميرا</text><text x="394" y="237" text-anchor="end">Cat6</text></g></svg>`;
  },
};
const lanePic = (c) => (!c.art
  ? `<figure class="lane__photo"><img src="${esc(c.image.src)}" width="${c.image.w}" height="${c.image.h}" alt="${esc(c.image.alt)}" loading="lazy" decoding="async"></figure>`
  : `<figure class="lane__photo lane__photo--art">${ART[c.art.kind](c.art.alt)}</figure>`);
const channels = `<section class="band shchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', d.channels.title, d.channels.lede)}
    <ol class="lanes">
${d.channels.items.map((c, i) => `      <li class="lane">${lanePic(c)}<div class="lane__text"><span class="lane__n nums" aria-hidden="true">${i + 1}/${d.channels.items.length}</span><h3>${icon(c.icon)}${esc(c.title)}</h3><p>${esc(c.text)}</p></div></li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- setup: the flow drawn against the life of the site, with the payment plan on it ---------- */
const cfg = d.config;
for (const p of cfg.plans) { const s = p.parts.reduce((a, x) => a + x.pct, 0); if (s !== 100) throw new Error(`plan ${p.name} sums to ${s}%`); }
const siteRuns = [];
cfg.stages.forEach((s) => { const last = siteRuns[siteRuns.length - 1]; if (last && last.site === s.site) last.span++; else siteRuns.push({ site: s.site, span: 1 }); });
const marks = (i) => cfg.plans.map((p, k) => p.parts.filter((x) => x.at === i).map((x) => `<span class="paym" data-plan="${k}"${k ? ' hidden' : ''}><b class="nums">${x.pct}%</b>${esc(x.when)}</span>`).join('')).join('');
const setup = `<section class="band shcfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', cfg.title, cfg.lede)}
    <div class="flowbar">
      <h3>${icon('i-workflow')}${esc(cfg.stagesTitle)}</h3>
      <div class="seg" role="group" aria-label="${esc(cfg.planTitle)}">${cfg.plans.map((p, i) => `<button class="seg__opt" type="button" data-plan="${i}" aria-pressed="${i === 0}"><bdi dir="ltr">${esc(p.name)}</bdi></button>`).join('')}</div>
    </div>
    <div class="tl" style="--n:${cfg.stages.length}">
      <ol class="tl__site" aria-label="حالة موقع العميل">${siteRuns.map((r) => `<li style="grid-column: span ${r.span}">${esc(r.site)}</li>`).join('')}</ol>
      <ol class="tl__stages">
${cfg.stages.map((s, i) => `        <li class="st${s.key === 'wiring' ? ' st--key' : ''}"><span class="st__site">${esc(s.site)}</span><span class="st__no nums" aria-hidden="true">${i + 1}</span><b class="st__name">${esc(s.name)}</b><span class="st__days nums">${esc(s.days)}</span>${s.flag ? `<p class="st__flag">${esc(s.flag)}</p>` : ''}<ul class="st__tasks">${s.tasks.map((t) => `<li>${esc(t)}</li>`).join('')}</ul><div class="st__pay">${marks(i)}</div></li>`).join('\n')}
      </ol>
    </div>
    <div class="shcfg__foot">
      <p class="shcfg__note">${icon('i-wallet')}<span>${esc(cfg.planNote)}</span></p>
      <div class="shcfg__clauses"><h3>${icon('i-file-text')}${esc(cfg.clausesTitle)}</h3><ol>${cfg.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ol></div>
    </div>
    <p class="recipe__edit">${icon('i-user-cog')}${esc(cfg.editable)}</p>
    <p class="recipe__bridge">${esc(cfg.bridge)}</p>
  </div>
</section>`;

/* ---------- roles: who sees what, as one matrix ---------- */
const R = d.roles;
for (const row of R.matrix) if (row.cells.length !== R.items.length) throw new Error(`matrix row ${row.label}`);
const roles = `<section class="band shroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', R.title, R.lede)}
    <div class="mtx" role="region" aria-labelledby="roles-title" tabindex="0">
      <table><colgroup><col class="mtx__lbl">${R.items.map(() => "<col>").join("")}</colgroup>
        <thead><tr><td></td>${R.items.map((r) => `<th scope="col"><span class="mtx__av" aria-hidden="true">${esc(r.letter)}</span><b>${esc(r.name)}</b><small>${esc(r.who)}</small></th>`).join('')}</tr></thead>
        <tbody>
${R.matrix.map((row) => `          <tr><th scope="row">${esc(row.label)}</th>${row.cells.map((c) => `<td>${c ? `${icon('i-check')}<span class="sr">نعم</span>` : '<span class="mtx__no" aria-hidden="true"></span><span class="sr">لا</span>'}</td>`).join('')}</tr>`).join('\n')}
        </tbody>
      </table>
    </div>
    <p class="mtx__hint" aria-hidden="true">اسحب الجدول أفقيًا لترى بقية الأدوار.</p>
    <p class="mtx__note">${esc(R.matrixNote)}</p>
  </div>
</section>`;

/* ---------- questions: two columns ---------- */
const faq = `<section class="band shfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle)}
    <div class="qa shfaq__list">
${[d.faq.slice(0, Math.ceil(d.faq.length / 2)), d.faq.slice(Math.ceil(d.faq.length / 2))].map((col, c) => `      <div class="shfaq__col">\n${col.map((f, i) => `        <details class="qa__item"${c + i === 0 ? ' open' : ''}><summary>${esc(f.q)}</summary><p>${esc(f.a)}</p></details>`).join('\n')}\n      </div>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, channels, setup, roles, faq];
export const styles = ['page.css'];
export const scripts = ['page.js'];
for (const k of ['korder', 'kpains', 'kchan', 'kcfg', 'kroles', 'kfaq', 'kplan', 'swap__item', 'pane__btn', 'splits__opt']) if (sections.join('').includes(k)) throw new Error(`leftover ${k}`);
console.log(`smart-home-lighting-security: ${o.lines.length} lines, ${pl.points.length} plan points, total ${fmt(total)}`);
