// The curtains and blinds page's own sections. Built by the shared builder: node marketing/site/solutions/build.mjs curtains-blinds
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

/* ---------- the demo order: the house's windows drawn to scale, and the quote by room ---------- */
// one centimetre is one SVG unit everywhere, so every window is drawn at the same scale
const windowSvg = (win) => {
  const span = win.kind === 'wave' ? win.track : win.w + 8;
  const W = span + 64, H = win.h + 84, x = (W - win.w) / 2, y = 44, t0 = (W - span) / 2;
  const dimW = `<g class="cw-dim"><line x1="${x}" y1="${y + win.h + 18}" x2="${x + win.w}" y2="${y + win.h + 18}"/><line x1="${x}" y1="${y + win.h + 12}" x2="${x}" y2="${y + win.h + 24}"/><line x1="${x + win.w}" y1="${y + win.h + 12}" x2="${x + win.w}" y2="${y + win.h + 24}"/><text x="${W / 2}" y="${y + win.h + 38}" text-anchor="middle">${win.w} سم</text></g>`;
  const hx = W - 12;
  const dimH = `<g class="cw-dim"><line x1="${hx}" y1="${y}" x2="${hx}" y2="${y + win.h}"/><line x1="${hx - 6}" y1="${y}" x2="${hx + 6}" y2="${y}"/><line x1="${hx - 6}" y1="${y + win.h}" x2="${hx + 6}" y2="${y + win.h}"/><text x="${hx - 6}" y="${y + win.h / 2}" text-anchor="middle" transform="rotate(-90 ${hx - 6} ${y + win.h / 2})">${win.h} سم</text></g>`;
  const glass = `<rect class="cw-glass" x="${x}" y="${y}" width="${win.w}" height="${win.h}"/><line class="cw-mull" x1="${x + win.w / 2}" y1="${y}" x2="${x + win.w / 2}" y2="${y + win.h}"/>`;
  let dress;
  if (win.kind === 'wave') {
    const folds = Math.round(span / 18), fw = span / folds;
    const wave = Array.from({ length: folds }, (_, i) => `q${fw / 4} 6 ${fw / 2} 0 t${fw / 2} 0`).join('');
    const stack = span * 0.16;
    const pleats = (sx) => Array.from({ length: 6 }, (_, i) => `<line x1="${sx + (i + 0.5) * stack / 6}" y1="${y - 6}" x2="${sx + (i + 0.5) * stack / 6}" y2="${y + win.h + 4}"/>`).join('');
    dress = `<line class="cw-track" x1="${t0}" y1="${y - 8}" x2="${t0 + span}" y2="${y - 8}"/>
<path class="cw-sheer" d="M${t0} ${y - 6} H${t0 + span} V${y + win.h + 2} H${t0} Z"/><path class="cw-hem" d="M${t0} ${y + win.h + 2} ${wave}"/>
<rect class="cw-black" x="${t0}" y="${y - 6}" width="${stack}" height="${win.h + 10}"/><g class="cw-pleat">${pleats(t0)}</g>
<rect class="cw-black" x="${t0 + span - stack}" y="${y - 6}" width="${stack}" height="${win.h + 10}"/><g class="cw-pleat">${pleats(t0 + span - stack)}</g>${win.motor ? `<rect class="cw-motor" x="${t0 + span - 18}" y="${y - 22}" width="18" height="12" rx="3"/>` : ''}`;
  } else {
    const drop = Math.round(win.h * 0.55);
    dress = `<rect class="cw-cassette" x="${t0}" y="${y - 10}" width="${span}" height="10" rx="2"/><rect class="cw-roll" x="${x}" y="${y}" width="${win.w}" height="${drop}"/><rect class="cw-bar" x="${x}" y="${y + drop - 3}" width="${win.w}" height="4" rx="2"/>`;
  }
  return `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" style="width:calc(var(--s) * ${W}px)" aria-hidden="true">${glass}${dress}${dimW}${dimH}</svg>`;
};

const o = d.order;
const groups = [...o.rooms.map((r) => ({ key: r.key, name: r.name })), { key: 'shared', name: o.sharedLabel }];
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - (o.discount || 0), vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const roomSum = (k) => o.lines.filter((l) => l.part === k).reduce((a, l) => a + l.amount, 0);
const winCount = o.rooms.reduce((a, r) => a + r.windows.length, 0);

const order = `<section class="band corder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="rooms" role="group" aria-label="اختر غرفة">
      <button class="rooms__opt" type="button" data-room="all" aria-pressed="true">${esc(o.allLabel)} <span class="nums">${winCount}</span></button>${o.rooms.map((r) => `<button class="rooms__opt" type="button" data-room="${r.key}" aria-pressed="false">${esc(r.name)} <span class="nums">${r.windows.length}</span></button>`).join('')}
    </div>
    <div class="corder__body">
      <div class="house">
${o.rooms.map((r) => `        <section class="house__room" data-room="${r.key}" aria-label="${esc(r.name)}">
          <h3>${esc(r.name)}</h3>
          <ul class="house__wins">${r.windows.map((w) => `<li class="cwin">${windowSvg(w)}<p><b>${esc(w.label)}</b><span class="nums">${w.w} × ${w.h} سم</span><span>${esc(w.layers)}${w.motor ? '، بموتور' : ''}</span></p></li>`).join('')}</ul>
        </section>`).join('\n')}
        <p class="house__note">${icon('i-ruler')}<span>${esc(o.fabricNote)}</span></p>
      </div>
      <div class="cquote" data-ui>
        <div class="cquote__head"><span><b>${esc(o.docTitle)}</b><span class="nums">${esc(o.quoteRef)}</span></span><span class="cquote__to">${esc(o.client)}<small><bdi class="nums">${esc(o.ref)}</bdi>، ${esc(o.branch)}</small></span></div>
        <table>
          <caption class="sr">بنود عرض السعر حسب الغرفة</caption>
          <colgroup><col><col class="cquote__amt"></colgroup>
          <thead class="sr"><tr><th scope="col">البند</th><th scope="col">المبلغ بالريال</th></tr></thead>
${groups.map((g) => `          <tbody data-room="${g.key}">
            <tr class="cquote__room"><th scope="rowgroup" colspan="2"><span class="cquote__rh"><span>${esc(g.name)}</span><span class="nums">${fmt(roomSum(g.key))}</span></span></th></tr>
${o.lines.filter((l) => l.part === g.key).map((l) => `            <tr><td>${esc(l.name)}<small class="nums">${esc(l.qty)} × ${esc(l.rate)}</small></td><td class="nums">${fmt(l.amount)}</td></tr>`).join('\n')}
          </tbody>`).join('\n')}
          <tfoot class="nums">
            <tr><th scope="row">المجموع</th><td>${fmt(sub)}</td></tr>
            <tr><th scope="row">${esc(o.discountLabel)}</th><td dir="ltr">−${fmt(o.discount)}</td></tr>
            <tr><th scope="row">ضريبة القيمة المضافة 15%</th><td>${fmt(vat)}</td></tr>
            <tr class="cquote__total"><th scope="row">الإجمالي شامل الضريبة</th><td>${fmt(total)} ر.س</td></tr>
          </tfoot>
        </table>
        <div class="cquote__plan"><b>${esc(o.planTitle)}</b><ol>${o.payments.map((p, i) => `<li><span class="nums">${i + 1}</span><span>${esc(p.when)}</span><b class="nums">${fmt(Math.round(total * p.pct) / 100)}</b></li>`).join('')}</ol></div>
        <p class="cquote__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;

/* ---------- pains: a curtain over each fix, drawn open as it reaches the reader ---------- */
const pains = `<section class="band cpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', d.pains.title, d.pains.lede)}
    <ol class="drapes">
${d.pains.items.map((p, i) => `      <li class="drape is-open"><p class="drape__pain">${esc(p.pain)}</p><div class="drape__win"><p class="drape__fix" id="fix-${i}">${icon(p.icon)}<span>${esc(p.fix)}</span></p><span class="drape__panel drape__panel--s" aria-hidden="true"></span><span class="drape__panel drape__panel--e" aria-hidden="true"></span><button class="drape__btn" type="button">أغلق الستارة</button></div></li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- channels: three tags hanging from one curtain rail ---------- */
const ch = d.channels.items;
const channels = `<section class="band cchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', d.channels.title, d.channels.lede)}
    <div class="rail" role="tablist" aria-labelledby="channels-title">
${ch.map((c, i) => `      <button class="ring" role="tab" type="button" id="tab-${c.key}" aria-controls="panel-${c.key}" aria-selected="${i === 0}"${i ? ' tabindex="-1"' : ''}><span class="ring__o" aria-hidden="true"></span><span class="ring__tag">${icon(c.icon)}${esc(c.title)}</span></button>`).join('\n')}
    </div>
${ch.map((c, i) => `    <div class="rail__panel" role="tabpanel" id="panel-${c.key}" aria-labelledby="tab-${c.key}" tabindex="0"><img src="${esc(c.image.src)}"${c.image.srcset ? ` srcset="${esc(c.image.srcset)}" sizes="(max-width: 760px) 100vw, 50vw"` : ''} width="${c.image.w}" height="${c.image.h}" alt="${esc(c.image.alt)}" loading="lazy" decoding="async"><div><h3>${esc(c.title)}</h3><p>${esc(c.text)}</p></div></div>`).join('\n')}
  </div>
</section>`;

/* ---------- ready-made setup: the flow on a tape of days, its payments marked on it, labels, rules ---------- */
const cfg = d.config;
const days = cfg.stages.reduce((a, s) => a + s.n, 0);
const setup = `<section class="band csetup" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', cfg.title, cfg.lede)}
    <div class="tape">
      <h3>${icon('i-workflow')}${esc(cfg.stagesTitle)}</h3>
      <ol class="tape__strip" style="--days:${days}">${cfg.stages.map((s, i) => `<li style="--n:${s.n}"><span class="tape__seg" aria-hidden="true"><span class="nums">${cfg.stages.slice(0, i + 1).reduce((a, x) => a + x.n, 0)}</span></span><b>${esc(s.name)}</b><span class="tape__days nums">${esc(s.days)}</span><small>${esc(s.does)}</small>${s.pay ? `<span class="tape__pay"><span>${icon('i-wallet')}${esc(s.pay)}</span><b class="nums">${fmt(Math.round(total * parseInt(s.pay, 10)) / 100)} ر.س</b></span>` : ''}</li>`).join('')}</ol>
      <p class="tape__note">${esc(cfg.stagesNote)}</p>
      <p class="tape__note">${esc(cfg.planNote)}</p>
    </div>
    <div class="csetup__grid">
      <div class="csetup__col">
        <h3>${icon('i-file-text')}${esc(cfg.clausesTitle)}</h3>
        <ol class="labels">${cfg.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
      </div>
      <div class="csetup__col">
        <h3>${icon('i-bell-ring')}${esc(cfg.rulesTitle)}</h3>
        <ul class="rules">${cfg.rules.map((r) => `<li><span><small>عند</small>${esc(r.when)}</span><span><small>يحدث</small>${esc(r.then)}</span></li>`).join('')}</ul>
        <p class="csetup__src">${esc(cfg.rulesNote)}</p>
      </div>
    </div>
    <p class="csetup__edit">${icon('i-user-cog')}${esc(cfg.editable)}</p>
    <p class="csetup__bridge">${esc(cfg.bridge)}</p>
  </div>
</section>`;

/* ---------- roles: who sees what, as a grid of permissions ---------- */
const R = d.roles;
const mark = { all: 'كامل', own: 'ما يخصّه فقط', none: 'لا' };
const roles = `<section class="band croles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', R.title, R.lede)}
    <p class="grid-key" aria-hidden="true"><span><i class="dot dot--all"></i>كامل</span><span><i class="dot dot--own"></i>ما يخصّه فقط</span><span><i class="dot dot--none"></i>لا</span></p>
    <div class="access">
      <table role="table" aria-label="${esc(R.title)}">
        <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">الدور</th>${R.cols.map((c) => `<th scope="col" role="columnheader">${esc(c)}</th>`).join('')}</tr></thead>
        <tbody role="rowgroup">
${R.items.map((r) => `          <tr role="row"><th scope="row" role="rowheader"><span class="access__who"><span class="role__av" aria-hidden="true">${esc(r.letter)}</span><span><b>${esc(r.name)}</b><small>${esc(r.who)}</small></span></span></th>${r.cells.map(([k, t], j) => `<td role="cell" data-col="${esc(R.cols[j])}" data-k="${k}"><i class="dot dot--${k}" aria-hidden="true"></i><span>${t ? esc(t) : `<span class="sr">${mark[k]}</span>`}</span></td>`).join('')}</tr>`).join('\n')}
        </tbody>
      </table>
    </div>
  </div>
</section>`;

/* ---------- FAQ: an open index, every answer on the page ---------- */
const faq = `<section class="band cfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle)}
    <dl class="index">
${d.faq.map((f, i) => `      <div><dt><span class="nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span>${esc(f.q)}</dt><dd>${esc(f.a)}</dd></div>`).join('\n')}
    </dl>
  </div>
</section>`;

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, channels, setup, roles, faq];
export const styles = ['curtains.css'];
export const scripts = ['curtains.js'];
console.log(`curtains-blinds: ${winCount} windows, total ${fmt(total)}, VAT ${fmt(vat)}`);
