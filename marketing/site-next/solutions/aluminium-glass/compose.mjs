// The aluminium & glass page's own sections. Built by the shared builder: node marketing/site-next/solutions/build.mjs aluminium-glass
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
const cell = (v) => (Array.isArray(v) ? `<span class="pill-s pill-s--${v[0]}">${esc(v[1])}</span>` : `<b class="nums">${esc(v)}</b>`);

/* ---------- the demo order: a schedule of openings drawn to one scale, with its title block ---------- */
const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - o.discount, vat = r2(net * 0.15), total = r2(net + vat);
for (const l of o.lines) {
  const each = l.w ? (l.w * l.h) / 1e6 : 0;
  const want = l.basis === 'm2' ? r2(each * l.count * l.price) : l.count * l.price;
  if (Math.abs(want - l.amount) > 0.001) throw new Error(`${l.code}: ${want} ≠ ${l.amount}`);
  l.each = each;
}
// one opening's elevation in millimetres (viewBox = the opening); strokes do not scale
const glyph = (l) => {
  const { w, h } = l, f = 40; // frame width in mm
  const frame = `<rect class="op__frame" x="0" y="0" width="${w}" height="${h}"/>`;
  const pane = (x, y, pw, ph) => `<rect class="op__glass" x="${x}" y="${y}" width="${pw}" height="${ph}"/>`;
  const arrow = (x, y, dir) => `<path class="op__mark" d="M${x - dir * 120} ${y} H${x + dir * 120} M${x + dir * 60} ${y - 60} L${x + dir * 120} ${y} L${x + dir * 60} ${y + 60}"/>`;
  switch (l.glyph) {
    case 'slide': { const half = w / 2; return frame + pane(f, f, half - f + 30, h - 2 * f) + pane(half - 30, f, half - f + 30, h - 2 * f) + arrow(w * 0.3, h / 2, 1) + arrow(w * 0.7, h / 2, -1); }
    case 'slidedoor': { const t = w / 3; return frame + [0, 1, 2].map((i) => pane(f + i * (t - f / 1.5), f, t - f / 1.5 + 20, h - 2 * f)).join('') + arrow(t * 0.5, h * 0.55, 1) + arrow(w - t * 0.5, h * 0.55, -1); }
    case 'tilt': return frame + pane(f, f, w - 2 * f, h - 2 * f) + `<path class="op__mark" d="M${f} ${f} L${w / 2} ${h - f} L${w - f} ${f}"/>`;
    case 'door': return frame + pane(f, f, w - 2 * f, h - 2 * f) + `<path class="op__mark" d="M${w - f} ${f} L${f} ${h / 2} L${w - f} ${h - f}"/><line class="op__mark" x1="${f + 90}" y1="${h * 0.48}" x2="${f + 90}" y2="${h * 0.56}"/>`;
    case 'shower': return `<rect class="op__glass op__glass--clear" x="0" y="0" width="${w}" height="${h}"/><line class="op__mark" x1="${w / 2}" y1="0" x2="${w / 2}" y2="${h}"/><line class="op__frame" x1="${w / 2 - 70}" y1="${h * 0.42}" x2="${w / 2 - 70}" y2="${h * 0.62}"/><line class="op__frame" x1="${w / 2 + 70}" y1="${h * 0.42}" x2="${w / 2 + 70}" y2="${h * 0.62}"/>`;
    case 'mirror': return `<rect class="op__mirror" x="0" y="0" width="${w}" height="${h}"/><rect class="op__mark" x="40" y="40" width="${w - 80}" height="${h - 80}"/><path class="op__shine" d="M${w * 0.18} ${h * 0.62} L${w * 0.42} ${h * 0.3} M${w * 0.3} ${h * 0.7} L${w * 0.5} ${h * 0.44}"/>`;
    default: return '';
  }
};
const tiles = o.lines.filter((l) => l.w).map((l) => `        <li class="op" data-part="${l.part}" style="--w:${l.w};--h:${l.h}">
          <div class="op__draw"><svg viewBox="-60 -60 ${l.w + 120} ${l.h + 120}" aria-hidden="true">${glyph(l)}</svg>${l.count > 1 ? `<span class="op__count nums" aria-hidden="true">× ${l.count}</span>` : ''}</div>
          <div class="op__meta">
            <p class="op__code"><b class="nums">${esc(l.code)}</b><span>${esc(l.where)}</span></p>
            <h3 class="op__name">${esc(l.name)}</h3>
            <p class="op__size nums"><bdi dir="ltr">${l.w} × ${l.h}</bdi> مم${l.count === 2 ? '، قطعتان' : l.count > 2 ? `، ${l.count} قطع` : ''}</p>
            <p class="op__calc nums">${l.basis === 'm2' ? `${(l.each * l.count).toFixed(2)} م² × ${fmt(l.price)}` : `${l.count === 2 ? 'قطعتان' : l.count === 1 ? 'قطعة' : l.count} × ${fmt(l.price)}`}<b>${fmt(l.amount)}</b></p>
          </div>
        </li>`).join('\n');
const service = o.lines.filter((l) => !l.w).map((l) => `<p class="sheet__service" data-part="${l.part}"><span><b class="nums">${esc(l.code)}</b>${esc(l.name)}، ${esc(l.where)}</span><b class="nums">${fmt(l.amount)}</b></p>`).join('');
const sums = Object.fromEntries(o.filters.map((f) => [f.key, f.key === 'all' ? sub : o.lines.filter((l) => l.part === f.key).reduce((a, l) => a + l.amount, 0)]));
const payAmts = o.payments.map((p) => Math.round(total * p.pct) / 100);
const order = `<section class="band agorder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="sheet" data-ui>
      <div class="sheet__bar">
        <div class="seg" role="group" aria-label="${esc(o.filterLabel)}">${o.filters.map((f, i) => `<button class="seg__opt" type="button" data-filter="${f.key}" aria-pressed="${i === 0}">${esc(f.label)}</button>`).join('')}</div>
        <p class="sheet__sum" aria-live="polite"><span id="sum-label">مجموع ${esc(o.filters[0].label)}</span><b class="nums" id="sum-val">${fmt(sub)}</b></p>
      </div>
      <script type="application/json" id="sheet-sums">${JSON.stringify(Object.fromEntries(o.filters.map((f) => [f.key, { label: f.label, sum: fmt(sums[f.key]) }])))}</script>
      <ol class="ops">
${tiles}
      </ol>
      ${service}
      <p class="sheet__scale">${icon('i-ruler')}${esc(o.scaleNote)}</p>
      <div class="tblock">
        <dl class="tblock__id">
          <div><dt>${esc(o.docTitle)}</dt><dd class="nums">${esc(o.quoteRef)}</dd></div>
          <div><dt>الطلب</dt><dd class="nums">${esc(o.ref)}</dd></div>
          <div><dt>العميل</dt><dd>${esc(o.client)}</dd></div>
          <div><dt>الفرع</dt><dd>${esc(o.branch)}</dd></div>
        </dl>
        <dl class="tblock__sum nums">
          <div><dt>المجموع</dt><dd>${fmt(sub)}</dd></div>
          <div><dt>${esc(o.discountLabel)}</dt><dd dir="ltr">−${fmt(o.discount)}</dd></div>
          <div><dt>ضريبة القيمة المضافة 15%</dt><dd>${fmt(vat)}</dd></div>
          <div class="tblock__total"><dt>الإجمالي شامل الضريبة</dt><dd>${fmt(total)} ر.س</dd></div>
        </dl>
        <div class="tblock__pay">
          <p><b>${esc(o.planTitle)}</b></p>
          <ol class="dues">${o.payments.map((p, i) => `<li><span class="dues__pct nums">${p.pct}%</span><span class="dues__when">${esc(p.when)}</span><span class="dues__amt nums">${fmt(payAmts[i])}</span></li>`).join('')}</ol>
        </div>
      </div>
      <p class="sheet__note">${esc(o.note)}</p>
    </div>
  </div>
</section>`;

/* ---------- pains: choose a problem, see the screen that answers it ---------- */
const p = d.pains;
const pains = `<section class="band agpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', p.title, p.lede)}
    <div class="diag">
      <div class="diag__list" role="tablist" aria-label="${esc(p.title)}" aria-orientation="vertical">
${p.items.map((x, i) => `        <button class="diag__tab" type="button" role="tab" id="tab-${x.key}" aria-controls="fix-${x.key}" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${icon(x.icon)}<span>${esc(x.pain)}</span></button>`).join('\n')}
      </div>
      <div class="diag__panels">
${p.items.map((x) => `        <div class="diag__panel" id="fix-${x.key}" aria-labelledby="tab-${x.key}">
          <p class="diag__pain">${esc(x.pain)}</p>
          <p class="diag__fix">${esc(x.fix)}</p>
          <div class="frag" data-ui role="img" aria-label="${esc(x.frag.title)}"><p class="frag__head nums">${esc(x.frag.title)}</p><ul>${x.frag.rows.map((r) => `<li><span class="nums">${esc(r[0])}</span>${cell(r[1])}</li>`).join('')}</ul></div>
        </div>`).join('\n')}
      </div>
    </div>
  </div>
</section>`;

/* ---------- channels: three lanes that merge into one order ---------- */
const c = d.channels;
const channels = `<section class="band agchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', c.title, c.lede)}
    <div class="lanes">
      <ol class="lanes__list">
${c.items.map((x) => `        <li class="lane">
          <img src="${esc(x.image.src)}"${x.image.srcset ? ` srcset="${esc(x.image.srcset)}" sizes="(max-width: 760px) 100vw, 30vw"` : ''} width="${x.image.w}" height="${x.image.h}" alt="${esc(x.image.alt)}" loading="lazy" decoding="async">
          <h3>${icon(x.icon)}${esc(x.title)}</h3>
          <ol class="lane__steps">${x.steps.map((s) => `<li>${esc(s)}</li>`).join('')}</ol>
        </li>`).join('\n')}
      </ol>
      <svg class="lanes__merge" viewBox="0 0 300 60" preserveAspectRatio="none" aria-hidden="true"><path d="M50 0 C50 34 150 26 150 60 M150 0 V60 M250 0 C250 34 150 26 150 60"/></svg>
      <p class="lanes__order"><span class="nums">${esc(o.ref)}</span>${esc(c.merge)}</p>
    </div>
  </div>
</section>`;

/* ---------- ready-made setup: the stages as a schedule by days, with plans, clauses and rules ---------- */
const g = d.config;
const span = Math.max(...Object.values(g.gantt).map((st) => st.reduce((a, s) => a + s.days, 0)));
const ganttRows = (key, hidden) => {
  let at = 0;
  return `<ol class="gantt__rows" data-mode="${key}"${hidden ? ' hidden' : ''}>${g.gantt[key].map((s) => { const row = `<li class="gantt__row${s.add ? ' is-add' : ''}" style="--at:${at};--d:${s.days}"><span class="gantt__name"><b>${esc(s.name)}</b><small>${esc(s.does)}</small></span><span class="gantt__track"><span class="gantt__bar"><span class="nums">${s.days === 2 ? 'يومان' : s.days <= 10 ? `${s.days} أيام` : `${s.days} يومًا`}</span></span></span></li>`; at += s.days; return row; }).join('')}</ol>`;
};
const totalDays = (key) => g.gantt[key].reduce((a, s) => a + s.days, 0);
const config = `<section class="band agcfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    ${head('setup', g.title, g.lede)}
    <div class="gantt" style="--span:${span}">
      <div class="gantt__top">
        <h3>${icon('i-workflow')}${esc(g.stagesTitle)}</h3>
        <div class="seg" role="group" aria-label="مكان التصنيع">${g.modes.map((m, i) => `<button class="seg__opt" type="button" data-mode="${m.key}" aria-pressed="${i === 0}">${esc(m.label)}</button>`).join('')}</div>
      </div>
${g.modes.map((m, i) => ganttRows(m.key, i > 0)).join('\n')}
      <p class="gantt__foot"><span class="nums" id="gantt-total" data-out="${totalDays('out')}" data-own="${totalDays('own')}">المجموع ${totalDays('out')} يومًا</span><span class="gantt__own" id="gantt-own" hidden>${esc(g.ownNote)}</span></p>
    </div>
    <div class="spec">
      <div class="spec__col">
        <h3>${icon('i-wallet')}${esc(g.planTitle)}</h3>
        <ul class="ratios">${g.plans.map((pl) => `<li><b class="nums">${esc(pl.name)}</b><span>${pl.parts.map((x) => `${x.pct}% ${esc(x.when)}`).join('، ')}</span></li>`).join('')}</ul>
        <p class="spec__note">${esc(g.planNote)}</p>
      </div>
      <div class="spec__col">
        <h3>${icon('i-file-text')}${esc(g.clausesTitle)}</h3>
        <ul class="clauses">${g.clauses.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>
      </div>
      <div class="spec__col">
        <h3>${icon('i-bell-ring')}${esc(g.rulesTitle)}</h3>
        <ul class="rules">${g.rules.map((x) => `<li><span>${esc(x.when)}</span>${icon('i-arrow', 'ic ic--dir')}<b>${esc(x.then)}</b></li>`).join('')}</ul>
      </div>
    </div>
    <p class="spec__edit">${icon('i-user-cog')}${esc(g.editable)}</p>
    <p class="spec__bridge">${esc(g.bridge)}</p>
  </div>
</section>`;

/* ---------- roles: a relay, each role hands its work to the next ---------- */
const rl = d.roles;
const roles = `<section class="band agroles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', rl.title, rl.lede)}
    <ol class="relay">
${rl.items.map((x) => `      <li class="relay__item">
        <span class="relay__av" aria-hidden="true">${esc(x.letter)}</span>
        <div class="relay__body"><h3>${esc(x.name)}<small>${esc(x.who)}</small></h3><ul>${x.does.map((y) => `<li>${esc(y)}</li>`).join('')}</ul></div>
        ${x.hands ? `<p class="relay__hand">${icon('i-arrow', 'ic ic--hand')}<span>يسلّم: <b>${esc(x.hands)}</b></span></p>` : ''}
      </li>`).join('\n')}
    </ol>
  </div>
</section>`;

/* ---------- FAQ: every answer open, grouped by the job it belongs to ---------- */
const faq = `<section class="band agfaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle, '')}
    <div class="topics">
${d.faqGroups.map((t) => `      <div class="topic"><h3>${esc(t)}</h3><dl>${d.faq.filter((f) => f.group === t).map((f) => `<div><dt>${esc(f.q)}</dt><dd>${esc(f.a)}</dd></div>`).join('')}</dl></div>`).join('\n')}
    </div>
  </div>
</section>`;

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, channels, config, roles, faq];
export const styles = ['page.css'];
export const scripts = ['page.js'];
console.log(`aluminium-glass: total ${fmt(total)}, VAT ${fmt(vat)}, dues ${payAmts.map(fmt).join(' + ')}`);
