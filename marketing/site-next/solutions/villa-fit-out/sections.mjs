#!/usr/bin/env node
// The fit-out page's own sections. Run after the shared build:
//   node marketing/site-next/solutions/build.mjs villa-fit-out && node marketing/site-next/solutions/villa-fit-out/sections.mjs
// The shared build writes the head, the hero line, the form, the footer and the consent banner (and checks the order total
// and the payment plan); this script replaces the sections between the hero and the form with forms of this sector's own,
// and links villa.css and villa.js. No dependencies.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const d = JSON.parse(readFileSync(join(here, 'data.json'), 'utf8'));
const out = join(here, 'index.html');
let html = readFileSync(out, 'utf8');

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const fmt = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const icon = (id, cls = 'ic') => `<svg class="${cls}" aria-hidden="true"><use href="#${id}"/></svg>`;
const days = (n) => (n === 1 ? 'يوم' : n === 2 ? 'يومان' : n <= 10 ? `${n} أيام` : `${n} يومًا`);
const head = (id, title, lede) => `<div class="sec-head"><h2 class="head" id="${id}-title">${esc(title)}</h2>${lede ? `<p class="lead">${esc(lede)}</p>` : ''}</div>`;

/* ---------- the demo project: stages on a day scale with their payments, and the quote under its stages ---------- */
const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - (o.discount || 0), vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const payAmt = (i) => Math.round(total * o.payments[i].pct) / 100;
const paid = o.payments.reduce((a, _, i) => a + payAmt(i), 0);
if (Math.abs(paid - total) > 0.001) throw new Error(`payments ${paid} ≠ ${total}`);
const span = o.stages.reduce((a, s) => a + s.days, 0);
let at = 0;
const sched = o.stages.map((s) => { const r = { ...s, start: at }; at += s.days; return r; });
const groups = sched.filter((s) => o.lines.some((l) => l.part === s.key));
const ticks = Array.from({ length: Math.floor(span / 10) + 1 }, (_, i) => i * 10);

const order = `<section class="band vorder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <figure class="sched" aria-labelledby="sched-cap">
      <div class="sched__axis nums" aria-hidden="true"><span></span><span class="sched__ticks" style="--span:${span}">${ticks.map((t) => `<i style="--at:${t}">اليوم ${t}</i>`).join('')}</span></div>
      <ol class="sched__rows" style="--span:${span}">
${sched.map((s) => {
  const lined = groups.includes(s);
  const pay = s.pay != null ? `<span class="sched__pin nums"><b>${o.payments[s.pay].pct}%</b>${fmt(payAmt(s.pay))}</span>` : '';
  const label = `<span class="sched__name">${esc(s.name)}</span><span class="sched__who">${esc(s.owner)}، <span class="nums">${days(s.days)}</span></span>`;
  const bar = `<span class="sched__lane"><span class="sched__bar${s.start / span > 0.7 ? ' sched__bar--end' : ''}" style="--at:${s.start};--d:${s.days}">${pay}</span></span>`;
  return `        <li class="sched__row${lined ? ' is-lined' : ''}" data-k="${s.key}">${lined ? `<button class="sched__btn" type="button" aria-pressed="false" aria-controls="g-${s.key}">${label}</button>` : `<span class="sched__btn">${label}</span>`}${bar}</li>`;
}).join('\n')}
      </ol>
      <figcaption class="sched__cap" id="sched-cap">${esc(o.ribbonCaption)}</figcaption>
    </figure>
    <div class="vorder__body">
      <div class="ledger" data-ui>
        <div class="ledger__head"><span><b>${esc(o.docTitle)}</b><span class="ref nums">${esc(o.quoteRef)}</span></span><span class="ledger__to">${esc(o.client)}<small><span class="nums">${esc(o.ref)}</span>، ${esc(o.branch)}</small></span></div>
${groups.map((g) => `        <section class="ledger__group" id="g-${g.key}" data-k="${g.key}" aria-label="${esc(g.name)}">
          <h3>${esc(g.name)}${g.pay != null ? `<small class="nums">دفعة ${o.payments[g.pay].pct}% عند بدء المرحلة</small>` : ''}</h3>
          <ul>
${o.lines.filter((l) => l.part === g.key).map((l) => `            <li><span class="ledger__name">${esc(l.name)}<small><span class="nums">${esc(l.qty)} × ${esc(l.unit)}</span></small></span><span class="by by--${l.by}">${esc(o.executors[l.by])}${l.po ? `<small class="nums">${esc(l.po)}</small>` : ''}</span><span class="ledger__amt nums">${fmt(l.amount)}</span></li>`).join('\n')}
          </ul>
        </section>`).join('\n')}
      </div>
      <aside class="tally" aria-label="الإجمالي وخطة الدفعات">
        <div class="tally__sum nums">
          <div><span>المجموع</span><span>${fmt(sub)}</span></div>
          <div><span>${esc(o.discountLabel)}</span><span dir="ltr">−${fmt(o.discount)}</span></div>
          <div><span>ضريبة القيمة المضافة 15%</span><span>${fmt(vat)}</span></div>
          <div class="tally__total"><span>الإجمالي شامل الضريبة</span><span>${fmt(total)} ر.س</span></div>
        </div>
        <b class="tally__plan">${esc(o.planTitle)}</b>
        <ol class="tally__pays">${o.payments.map((p, i) => `<li><span class="nums">${p.pct}%</span><span>${esc(p.when)}</span><span class="nums">${fmt(payAmt(i))}</span></li>`).join('')}</ol>
        <p class="tally__note">${esc(o.note)}</p>
      </aside>
    </div>
  </div>
</section>`;

/* ---------- pains: a snag list, closed in the order the reader meets it ---------- */
const p = d.pains;
const pains = `<section class="band vpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', p.title, p.lede)}
    <div class="sheet">
      <div class="sheet__head"><b>${esc(p.sheetTitle)}</b><span>${esc(p.sheetRef)}</span><span class="sheet__count nums" aria-hidden="true"><span data-closed>${p.items.length}</span> من ${p.items.length} أُغلقت</span></div>
      <ol class="snags">
${p.items.map((s) => `        <li class="snag is-closed"><span class="snag__box" aria-hidden="true">${icon('i-check')}</span><div class="snag__text"><p class="snag__where">${icon(s.icon)}${esc(s.where)}</p><p class="snag__pain">${esc(s.pain)}</p><p class="snag__fix">${esc(s.fix)}</p></div></li>`).join('\n')}
      </ol>
    </div>
  </div>
</section>`;

/* ---------- channels: three sources merge into one order ---------- */
const c = d.channels;
const channels = `<section class="band vchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', c.title, c.lede)}
    <div class="merge">
      <ul class="merge__src">
${c.items.map((x) => `        <li class="src"><div class="frag" data-ui aria-hidden="true"><p class="frag__head"><b>${esc(x.frag.title)}</b><small>${esc(x.frag.sub)}</small></p><ul>${x.frag.rows.map((row) => `<li><span>${esc(row[0])}</span>${Array.isArray(row[1]) ? `<span class="pill-s pill-s--${row[1][0]}">${esc(row[1][1])}</span>` : `<b class="nums">${esc(row[1])}</b>`}</li>`).join('')}</ul></div><h3>${icon(x.icon)}${esc(x.title)}</h3><p>${esc(x.text)}</p></li>`).join('\n')}
      </ul>
      <svg class="merge__lines" viewBox="0 0 600 80" preserveAspectRatio="none" aria-hidden="true"><path d="M100 0 C100 50 300 30 300 80"/><path d="M300 0 V80"/><path d="M500 0 C500 50 300 30 300 80"/></svg>
      <p class="merge__order">${icon('i-clipboard-list')}<span><b class="nums">${esc(c.merge.split('،')[0])}</b>${esc(c.merge.slice(c.merge.indexOf('،') + 1).trim())}</span></p>
      <p class="merge__report">${icon('i-chart-column')}${esc(c.report)}</p>
    </div>
  </div>
</section>`;

/* ---------- ready-made setup: the settings editor, in tabs ---------- */
const g = d.config;
const block = (s, extra = '') => `<li class="blk${extra}"><span class="blk__grip" aria-hidden="true">${icon('i-grip-vertical')}</span><span class="blk__main"><b>${esc(s.name)}</b><small>${esc(s.does)}</small></span><span class="blk__meta"><span class="blk__owner">${esc(s.owner)}</span><span class="nums">${esc(s.days)}</span></span></li>`;
const afterAgreement = g.stages.findIndex((s) => s.name.startsWith('العقد')) + 1;
const tabs = [
  ['stages', g.stagesTitle, `<div class="mode" role="group" aria-label="${esc(g.modeLabel)}"><span class="mode__label">${esc(g.modeLabel)}</span>${g.modes.map((m, i) => `<button type="button" class="mode__opt" data-mode="${m.key}" aria-pressed="${i === 0}">${esc(m.name)}</button>`).join('')}</div>
            <ol class="blocks">${g.stages.slice(0, afterAgreement).map((s) => block(s)).join('')}${g.factoryStages.map((s) => block(s, ' blk--factory')).join('')}${g.stages.slice(afterAgreement).map((s) => block(s)).join('')}</ol>
            <p class="editor__note" data-note-suppliers>${esc(g.stagesNote)} ${esc(g.supplierNote)}</p>
            <p class="editor__note" data-note-factory>${esc(g.factoryNote)}</p>`],
  ['plans', g.plansTitle, `<ol class="plines">${g.planLines.map((l) => `<li><b class="nums">${esc(l.pct)}</b><span>${icon('i-calendar-clock')}${esc(l.event)}</span></li>`).join('')}</ol>
            <p class="editor__note">${esc(g.planNote)}</p>`],
  ['rules', g.rulesTitle, `<ol class="rules">${g.rules.map((r) => `<li><p><span>عند</span>${esc(r.when)}</p>${r.if ? `<p><span>وإذا</span>${esc(r.if)}</p>` : ''}<p><span>فافعل</span>${esc(r.then)}</p></li>`).join('')}</ol>
            <p class="editor__note">${esc(g.rulesNote)}</p>`],
  ['clauses', g.clausesTitle, `<ol class="clauses">${g.clauses.map((x) => `<li>${esc(x)}</li>`).join('')}</ol>`],
];
const config = `<section class="band vcfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', g.title, g.lede)}
    <div class="editor" data-ui>
      <div class="editor__bar"><span>${icon('i-user-cog')}${esc(g.tabsLabel)}</span><span class="editor__draft">مسودة</span></div>
      <div class="editor__tabs" role="tablist" aria-label="${esc(g.tabsLabel)}">${tabs.map(([k, t], i) => `<button type="button" role="tab" id="tab-${k}" aria-controls="panel-${k}" aria-selected="${i === 0}"${i ? ' tabindex="-1"' : ''}>${esc(t)}</button>`).join('')}</div>
${tabs.map(([k, t, body]) => `      <div class="editor__panel" role="tabpanel" id="panel-${k}" aria-labelledby="tab-${k}" tabindex="0">
            <h3 class="editor__title">${esc(t)}</h3>
            ${body}
      </div>`).join('\n')}
    </div>
    <p class="vcfg__edit">${icon('i-workflow')}${esc(g.editable)}</p>
  </div>
</section>`;

/* ---------- roles: who works in which stage ---------- */
const r = d.roles;
const roles = `<section class="band vroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', r.title, r.lede)}
    <div class="matrix">
      <div class="grid__scroll"><table class="grid">
        <caption class="sr">${esc(r.matrixLabel)}</caption>
        <thead><tr><th scope="col"><span class="sr">الدور</span></th>${r.columns.map((x) => `<th scope="col">${esc(x)}</th>`).join('')}</tr></thead>
        <tbody>
${r.items.map((x, i) => `          <tr data-i="${i}"><th scope="row"><button type="button" class="who" aria-pressed="${i === 0}" aria-controls="role-${i}"><span class="who__av" aria-hidden="true">${esc(x.letter)}</span><span class="who__name">${esc(x.name)}</span></button></th>${x.at.map((v, j) => `<td>${v ? `<span class="on" aria-hidden="true"></span><span class="sr">يعمل في ${esc(r.columns[j])}</span>` : ''}</td>`).join('')}</tr>`).join('\n')}
        </tbody>
      </table></div>
      <div class="duty" aria-live="polite">
${r.items.map((x, i) => `        <div class="duty__item" id="role-${i}" data-i="${i}"><p class="duty__who"><span class="who__av" aria-hidden="true">${esc(x.letter)}</span><span><b>${esc(x.name)}</b><small>${esc(x.who)}</small></span></p><ul>${x.does.map((t) => `<li>${icon('i-check')}${esc(t)}</li>`).join('')}</ul></div>`).join('\n')}
      </div>
    </div>
  </div>
</section>`;

/* ---------- FAQ: every answer open, in two columns ---------- */
const faq = `<section class="band vfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle)}
    <div class="answers">
${d.faq.map((f) => `      <div class="answer"><h3>${esc(f.q)}</h3><p>${esc(f.a)}</p></div>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- splice into the shared build's output ---------- */
const from = html.indexOf('<section class="band korder"');
const to = html.indexOf('<section class="demo band"');
if (from < 0 || to < 0) throw new Error('run build.mjs villa-fit-out first');
html = html.slice(0, from) + [order, pains, channels, config, roles, faq].join('\n') + '\n' + html.slice(to);
html = html.replace('<link rel="stylesheet" href="../sector.css">', '<link rel="stylesheet" href="../sector.css">\n<link rel="stylesheet" href="villa.css">');
html = html.replace('<script src="../sector.js" defer></script>', '<script src="../sector.js" defer></script>\n<script src="villa.js" defer></script>');
writeFileSync(out, html);
console.log(`spliced villa-fit-out sections (${(html.length / 1024).toFixed(1)} kB); total ${fmt(total)}, VAT ${fmt(vat)}, ${span} days`);
