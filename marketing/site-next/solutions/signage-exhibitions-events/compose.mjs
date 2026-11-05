// The signage, exhibitions and events page's own sections. Built by the shared builder: node marketing/site-next/solutions/build.mjs signage-exhibitions-events
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
const head = (id, title, lede) => `<div class="sec-head"><h2 class="head" id="${id}-title">${esc(title)}</h2>${lede ? `<p class="lead">${esc(lede)}</p>` : ''}</div>`;

/* ---------- the demo order: the booth from above, linked to the quote grouped by where each line is made ---------- */
// one metre is 60 SVG units; the booth is 6 × 4 m, walled on the back and one side, open to two aisles
const M = 60, X = 70, Y = 70, BW = 6 * M, BD = 4 * M;
const tiles = [...Array(5)].map((_, i) => `<line x1="${X + (i + 1) * M}" y1="${Y}" x2="${X + (i + 1) * M}" y2="${Y + BD}"/>`).join('') + [...Array(3)].map((_, j) => `<line x1="${X}" y1="${Y + (j + 1) * M}" x2="${X + BW}" y2="${Y + (j + 1) * M}"/>`).join('');
const planSvg = `<svg viewBox="0 0 520 420" role="img" aria-label="${esc(d.order.planLabel)}">
<rect class="bp-floor" data-part="floor" x="${X}" y="${Y}" width="${BW}" height="${BD}"/><g class="bp-tiles" data-part="floor">${tiles}</g>
<rect class="bp-aisle" data-part="install" x="${X - 46}" y="${Y - 46}" width="${BW + 92}" height="${BD + 92}" rx="6"/>
<rect class="bp-fascia" data-part="letters" x="${X + 30}" y="${Y - 22}" width="${BW - 60}" height="14" rx="3"/>
<path class="bp-wall" data-part="structure" d="M${X} ${Y + BD} V${Y} H${X + BW}"/>
<line class="bp-print" data-part="print" x1="${X + 24}" y1="${Y + 9}" x2="${X + 4 * M}" y2="${Y + 9}"/>
<rect class="bp-screen" data-part="screen" x="${X + 5}" y="${Y + 80}" width="9" height="84" rx="2"/>
${[1, 3, 5].map((m) => `<circle class="bp-light" data-part="light" cx="${X + m * M}" cy="${Y + 30}" r="7"/>`).join('')}
<rect class="bp-counter" data-part="counter" x="${X + 4 * M}" y="${Y + 2.6 * M}" width="${1.4 * M}" height="${0.6 * M}" rx="4"/>
<text class="bp-lbl" x="${X + BW / 2}" y="${Y + BD + 72}" text-anchor="middle">ممر</text><text class="bp-lbl" x="${X + BW + 64}" y="${Y + BD / 2}" text-anchor="middle" transform="rotate(90 ${X + BW + 64} ${Y + BD / 2})">ممر</text>
<g class="bp-dim"><line x1="${X}" y1="${Y + BD + 16}" x2="${X + BW}" y2="${Y + BD + 16}"/><text x="${X + BW / 2}" y="${Y + BD + 34}" text-anchor="middle">6.00 م</text>
<line x1="${X - 16}" y1="${Y}" x2="${X - 16}" y2="${Y + BD}"/><text x="${X - 26}" y="${Y + BD / 2}" text-anchor="middle" transform="rotate(-90 ${X - 26} ${Y + BD / 2})">4.00 م</text></g>
</svg>`;

const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - (o.discount || 0), vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const groupSum = (k) => o.lines.filter((l) => l.group === k).reduce((a, l) => a + l.amount, 0);

const order = `<section class="band sorder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="sorder__body">
      <figure class="bplan">
        ${planSvg}
        <figcaption class="bplan__cap">${esc(o.planCaption)}</figcaption>
      </figure>
      <div class="squote" data-ui>
        <div class="squote__head"><span><b>${esc(o.docTitle)}</b><span class="nums">${esc(o.quoteRef)}</span></span><span class="squote__to">${esc(o.client)}<small><bdi class="nums">${esc(o.ref)}</bdi>، ${esc(o.branch)}</small></span></div>
${o.groups.map((g) => `        <section class="squote__group" aria-label="${esc(g.name)}">
          <h3><span>${esc(g.name)}</span><span class="nums">${fmt(groupSum(g.key))}</span></h3>
          <ul>${o.lines.filter((l) => l.group === g.key).map((l) => `<li><button class="squote__line" type="button" data-part="${l.part}" aria-pressed="false"><span>${esc(l.name)}<small class="nums">${esc(l.qty)}</small></span><span class="nums">${fmt(l.amount)}</span></button></li>`).join('')}</ul>
        </section>`).join('\n')}
        <dl class="squote__sum nums">
          <div><dt>المجموع</dt><dd>${fmt(sub)}</dd></div>
          <div><dt>${esc(o.discountLabel)}</dt><dd dir="ltr">−${fmt(o.discount)}</dd></div>
          <div><dt>ضريبة القيمة المضافة 15%</dt><dd>${fmt(vat)}</dd></div>
          <div class="squote__total"><dt>الإجمالي شامل الضريبة</dt><dd>${fmt(total)} ر.س</dd></div>
        </dl>
        <div class="squote__plan"><b>${esc(o.planTitle)}</b><ol>${o.payments.map((p) => `<li style="--w:${p.pct}"><span class="nums">${p.pct}%</span><b class="nums">${fmt(Math.round(total * p.pct) / 100)}</b><small>${esc(p.when)}</small></li>`).join('')}</ol></div>
        <p class="squote__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;

/* ---------- pains: each one a proof sheet with the client's note on it, then stamped approved with the fix ---------- */
const pains = `<section class="band spains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', d.pains.title, d.pains.lede)}
    <ol class="proofs">
${d.pains.items.map((p, i) => `      <li class="proof"><span class="proof__marks" aria-hidden="true"></span><p class="proof__pain">${esc(p.pain)}</p><div class="proof__fix"><span class="proof__stamp" aria-hidden="true">معتمد</span><p>${icon(p.icon)}<span>${esc(p.fix)}</span></p></div><span class="proof__slug nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span></li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- channels: three lit letters on one fascia, each lighting its own panel ---------- */
const ch = d.channels.items;
const channels = `<section class="band schan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', d.channels.title, d.channels.lede)}
    <div class="fascia" role="tablist" aria-labelledby="channels-title">
${ch.map((c, i) => `      <button class="fascia__tab" role="tab" type="button" id="tab-${c.key}" aria-controls="panel-${c.key}" aria-selected="${i === 0}"${i ? ' tabindex="-1"' : ''}>${icon(c.icon)}<span>${esc(c.title)}</span></button>`).join('\n')}
    </div>
${ch.map((c) => `    <div class="fascia__panel" role="tabpanel" id="panel-${c.key}" aria-labelledby="tab-${c.key}" tabindex="0"><img src="${esc(c.image.src)}" width="${c.image.w}" height="${c.image.h}" alt="${esc(c.image.alt)}" loading="lazy" decoding="async"><div><h3>${esc(c.title)}</h3><p>${esc(c.text)}</p><div class="chcard" data-ui><b>${esc(c.card.title)}</b><ul>${c.card.rows.map(([k, v]) => `<li><span>${esc(k)}</span><span class="nums">${esc(v)}</span></li>`).join('')}</ul></div></div></div>`).join('\n')}
  </div>
</section>`;

/* ---------- ready-made setup: the flow laid against the opening day, then documents, payments and terms ---------- */
const cfg = d.config, op = cfg.opening;
const before = cfg.stages.filter((s) => !s.after).reduce((a, s) => a + s.n, 0);
const afterDays = op.eventDays + cfg.stages.filter((s) => s.after).reduce((a, s) => a + s.n, 0);
const span = before + afterDays;
// day -before is column 1; the opening is the line after day -1; the event runs before the stages that come after it
let at = 1;
const bars = cfg.stages.map((s) => {
  if (s.after && at <= before + 1) at = before + 1 + op.eventDays;
  const bar = { ...s, start: at, from: at - 1 - before, to: at - 1 - before + s.n };
  at += s.n;
  return bar;
});
const tick = (day) => `<span class="ruler__tick nums" style="--c:${day + before + 1}">${day === 0 ? `الافتتاح ${esc(op.date)}` : day < 0 ? -day : `<bdi dir="ltr">+${day}</bdi>`}</span>`;
const when = (b) => (b.to <= 0 ? `من ${-b.from} يومًا قبل الافتتاح ${b.to === 0 ? 'حتى يومه' : `إلى ${-b.to} أيام قبله`}` : `بعد آخر أيام المعرض، من اليوم ${b.from} إلى ${b.to}`);
const setup = `<section class="band ssetup" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', cfg.title, cfg.lede)}
    <div class="ruler" style="--days:${span}">
      <h3>${icon('i-workflow')}${esc(cfg.stagesTitle)}</h3>
      <p class="ruler__key">الأرقام أيام قبل الافتتاح، و+ أيام بعد الافتتاح.</p>
      <div class="ruler__axis" aria-hidden="true">${[-35, -28, -21, -14, -7, 0, afterDays].filter((x) => x >= -before).map(tick).join('')}</div>
      <div class="ruler__grid">
        <span class="ruler__event" style="--c:${before + 1};--n:${op.eventDays}" aria-hidden="true"><span>${esc(op.label)}</span></span>
        <span class="ruler__open" style="--c:${before + 1}" aria-hidden="true"></span>
        <span class="ruler__today" style="--c:${before + 1 + op.today}" aria-hidden="true"><span>اليوم</span></span>
        <ol class="ruler__rows">${bars.map((b) => `<li class="ruler__row${b.added ? ' is-added' : ''}"><p class="ruler__name"><b>${esc(b.name)}</b><span class="nums">${esc(b.days)}</span>${b.added ? '<em>تضيفها</em>' : ''}</p><span class="ruler__bar${b.to <= op.today ? ' is-done' : b.from <= op.today ? ' is-live' : ''}" style="--c:${b.start};--n:${b.n}"><span class="sr">${when(b)}</span></span><small>${esc(b.does)}</small></li>`).join('')}</ol>
      </div>
      <p class="ruler__note">${esc(cfg.stagesNote)}</p>
    </div>
    <div class="ssetup__grid">
      <div class="ssetup__col">
        <h3>${icon('i-folder-lock')}${esc(cfg.docsTitle)}</h3>
        <ul class="docs">${cfg.docs.map((x) => `<li>${icon('i-file-text')}<span><b>${esc(x.name)}</b><small>${esc(x.stage)}</small></span></li>`).join('')}</ul>
        <p class="ssetup__src">${esc(cfg.docsNote)}</p>
      </div>
      <div class="ssetup__col">
        <h3>${icon('i-wallet')}${esc(cfg.planTitle)}</h3>
        <div class="splits" role="group" aria-label="${esc(cfg.planTitle)}">${cfg.plans.map((p, i) => `<button class="splits__opt" type="button" aria-pressed="${i === 0}" data-plan="${i}"><bdi dir="ltr">${esc(p.name)}</bdi></button>`).join('')}</div>
        ${cfg.plans.map((p, i) => `<ol class="splitbar" data-plan="${i}"${i ? ' hidden' : ''}>${p.parts.map((x) => `<li style="--w:${x.pct}"><b class="nums">${x.pct}%</b><span>${esc(x.when)}</span></li>`).join('')}</ol>`).join('')}
        <p class="ssetup__src">${esc(cfg.planNote)}</p>
      </div>
      <div class="ssetup__col">
        <h3>${icon('i-file-pen-line')}${esc(cfg.clausesTitle)}</h3>
        <ol class="terms">${cfg.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
      </div>
    </div>
    <p class="ssetup__edit">${icon('i-user-cog')}${esc(cfg.editable)}</p>
    <p class="ssetup__bridge">${esc(cfg.bridge)}</p>
  </div>
</section>`;

/* ---------- roles: an event pass for each person on the order ---------- */
const roles = `<section class="band sroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', d.roles.title, d.roles.lede)}
    <ul class="passes">
${d.roles.items.map((r) => `      <li class="pass"><div class="pass__top"><span class="pass__slot" aria-hidden="true"></span><span class="role__av" aria-hidden="true">${esc(r.letter)}</span><span><h3>${esc(r.name)}</h3><p class="pass__who">${esc(r.who)}</p></span></div><ul>${r.does.map((x) => `<li>${icon('i-check')}${esc(x)}</li>`).join('')}</ul></li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- FAQ: two columns of numbered questions ---------- */
const faq = `<section class="band sfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle)}
    <div class="sfaq__list">
${d.faq.map((f, i) => `      <details class="sfaq__item"${i < 2 ? ' open' : ''}><summary><span>${esc(f.q)}</span>${icon('i-plus')}</summary><p>${esc(f.a)}</p></details>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, channels, setup, roles, faq];
export const styles = ['signage.css'];
export const scripts = ['signage.js'];
console.log(`signage-exhibitions-events: ${bars.length} stages over ${span} days, total ${fmt(total)}, VAT ${fmt(vat)}`);
