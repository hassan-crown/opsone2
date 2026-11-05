// The flooring, walls and ceilings page's own sections. Built by the shared builder: node marketing/site/solutions/build.mjs flooring-walls-ceilings
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
const near = (a, b) => Math.abs(a - b) < 0.006;
const head = (id, title, lede) => `<div class="sec-head"><h2 class="head" id="${id}-title">${esc(title)}</h2>${lede ? `<p class="lead">${esc(lede)}</p>` : ''}</div>`;

/* ---------- the demo order: check every quantity, from the measured area to the amount ---------- */
const o = d.order;
for (const l of o.lines) {
  if (l.pack) {
    const need = l.measured * (1 + l.waste / 100);
    if (Math.ceil(need / l.pack - 1e-9) !== l.packs) throw new Error(`${l.name}: ${need} needs ${Math.ceil(need / l.pack)} × ${l.pack}, not ${l.packs}`);
    const ordered = l.unit === 'لفة' ? l.packs : r2(l.packs * l.pack);
    if (!near(ordered, l.orderedQty)) throw new Error(`${l.name}: ordered ${ordered} ≠ ${l.orderedQty}`);
    l.need = need;
  } else if (l.waste) {
    if (!near(l.measured * (1 + l.waste / 100), l.orderedQty)) throw new Error(`${l.name}: waste`);
  } else if (!near(l.measured, l.orderedQty)) throw new Error(`${l.name}: measured ≠ ordered`);
  if (!near(l.orderedQty * l.price, l.amount)) throw new Error(`${l.name}: ${l.orderedQty} × ${l.price} ≠ ${l.amount}`);
}
// the measured areas come from the plan: each floor material's rooms, the papered walls (3 m high) and the ceilings
const area = (rs) => r2(rs.reduce((a, r) => a + r.w * r.h, 0));
const rooms = o.rooms.filter((r) => !r.out);
const byFloor = (m) => area(rooms.filter((r) => r.floor === m));
const wallArea = r2(rooms.flatMap((r) => r.walls || []).reduce((a, [x1, y1, x2, y2]) => a + Math.hypot(x2 - x1, y2 - y1) * 3, 0));
const checks = { porcelain: byFloor('porcelain'), marble: byFloor('marble'), wallpaper: wallArea, gypsum: area(rooms.filter((r) => r.ceiling)), floorfit: area(rooms) };
for (const [part, want] of Object.entries(checks)) {
  const l = o.lines.find((x) => x.part === part && !x.measuredUnit);
  if (!near(l.measured, want)) throw new Error(`${part}: line says ${l.measured} m², plan says ${want}`);
}
const sub = r2(o.lines.reduce((a, l) => a + l.amount, 0));
const net = r2(sub - o.discount), vat = r2(net * 0.15), total = r2(net + vat);
if (!near(total, o.expectTotal)) throw new Error(`total ${total} ≠ ${o.expectTotal}`);
const payAmts = o.payments.map((p) => r2((total * p.pct) / 100));
if (!near(payAmts.reduce((a, b) => a + b, 0), total)) throw new Error('payments do not add up');

/* ---------- the plan: the ground floor to scale, each room filled with its material ---------- */
const S = 36; // px per metre
const W = 13 * S, H = 11.5 * S;
const px = (m) => +(m * S).toFixed(1);
const fills = { porcelain: 'url(#pat-porcelain)', marble: 'url(#pat-marble)', parquet: 'url(#pat-parquet)' };
const floorPart = (r) => `${r.floor} floorfit`;
const roomSvg = rooms.map((r, i) => {
  const [x, y, w, h] = [px(r.x), px(r.y), px(r.w), px(r.h)];
  const walls = (r.walls || []).map(([x1, y1, x2, y2]) => `<line class="fp__paper l-wall" data-part="wallpaper wallfit" x1="${px(x1)}" y1="${px(y1) + (y1 === 0 ? 5 : -5)}" x2="${px(x2)}" y2="${px(y2) + (y1 === 0 ? 5 : -5)}"/>`).join('');
  return `<g class="fp__room" style="--i:${i}">
      <rect class="fp__floor l-floor" data-part="${floorPart(r)}" x="${x}" y="${y}" width="${w}" height="${h}" fill="${fills[r.floor]}"/>
      ${r.ceiling ? `<rect class="fp__ceil l-ceiling" data-part="gypsum" x="${x + 8}" y="${y + 8}" width="${w - 16}" height="${h - 16}" rx="3"/>` : ''}
      ${walls}
      <text class="fp__name" x="${x + w / 2}" y="${y + h / 2 - 3}" text-anchor="middle">${esc(r.name)}</text>
      <text class="fp__area nums" x="${x + w / 2}" y="${y + h / 2 + 15}" text-anchor="middle">${fmt(r.w * r.h)} م²</text>
    </g>`;
}).join('\n    ');
const out = o.rooms.filter((r) => r.out).map((r) => `<rect class="fp__out" x="${px(r.x)}" y="${px(r.y)}" width="${px(r.w)}" height="${px(r.h)}" fill="url(#pat-out)"/>${r.name ? `<text class="fp__outname" x="${px(r.x + r.w / 2)}" y="${px(r.y + r.h / 2) + 5}" text-anchor="middle">${esc(r.name)}، خارج الطلب</text>` : ''}`).join('');
const planSvg = `<svg viewBox="-34 -40 ${W + 74} ${H + 62}" role="img" aria-labelledby="fp-title">
    <title id="fp-title">${esc(o.planLabel)}</title>
    <defs>
      <pattern id="pat-porcelain" width="${0.6 * S}" height="${1.2 * S}" patternUnits="userSpaceOnUse"><rect width="${0.6 * S}" height="${1.2 * S}" fill="#EEF1F4"/><path d="M0 0 H${0.6 * S} M0 0 V${1.2 * S}" stroke="#C9D2DC" stroke-width="1"/></pattern>
      <pattern id="pat-marble" width="${0.6 * S}" height="${0.6 * S}" patternUnits="userSpaceOnUse"><rect width="${0.6 * S}" height="${0.6 * S}" fill="#F7F6F2"/><path d="M0 0 H${0.6 * S} M0 0 V${0.6 * S}" stroke="#D8D4CA" stroke-width="1"/><path d="M3 15 C8 10 12 17 20 9" fill="none" stroke="#DCD7CC" stroke-width="0.8"/></pattern>
      <pattern id="pat-parquet" width="${1.2 * S}" height="${0.4 * S}" patternUnits="userSpaceOnUse"><rect width="${1.2 * S}" height="${0.4 * S}" fill="#F3EADF"/><path d="M0 0 H${1.2 * S} M0 ${0.2 * S} H${1.2 * S} M${0.3 * S} 0 V${0.2 * S} M${0.9 * S} ${0.2 * S} V${0.4 * S}" stroke="#DCCBB6" stroke-width="1"/></pattern>
      <pattern id="pat-out" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="10" height="10" fill="#fff"/><line x1="0" y1="0" x2="0" y2="10" stroke="#E4E9EE" stroke-width="3"/></pattern>
    </defs>
    ${out}
    ${roomSvg}
    <rect class="fp__shell" x="0" y="0" width="${W}" height="${H}"/>
    <g class="fp__dims"><line x1="0" y1="-20" x2="${W}" y2="-20"/><line x1="0" y1="-26" x2="0" y2="-14"/><line x1="${W}" y1="-26" x2="${W}" y2="-14"/><text x="${W / 2}" y="-28" text-anchor="middle">13.00 م</text>
      <line x1="${W + 20}" y1="0" x2="${W + 20}" y2="${H}"/><line x1="${W + 14}" y1="0" x2="${W + 26}" y2="0"/><line x1="${W + 14}" y1="${H}" x2="${W + 26}" y2="${H}"/><text x="${W + 34}" y="${H / 2}" text-anchor="middle" transform="rotate(90 ${W + 34} ${H / 2})">11.50 م</text></g>
  </svg>`;
const legend = [
  ['porcelain', 'بورسلين', 'l-floor'], ['marble', 'رخام', 'l-floor'], ['parquet', 'باركيه', 'l-floor'],
  ['wallpaper', 'ورق جدران', 'l-wall'], ['gypsum', 'سقف جبس بورد', 'l-ceiling'],
].map(([k, t, c]) => `<li class="fp__key fp__key--${k} ${c}"><span aria-hidden="true"></span>${t}</li>`).join('');

/* ---------- the takeoff: from the measured area to the quantity sold ---------- */
const unitOf = (l) => l.measuredUnit || 'م²';
const packs = (l) => (l.pack ? `${l.packs} ${l.packName === 'كرتون' ? 'كرتون' : l.packName === 'لفة' ? (l.packs > 2 ? 'لفات' : 'لفة') : l.packs > 2 ? 'قطع' : 'قطعة'}<small>× ${fmt(l.pack)} ${l.packName === 'قطعة' ? 'م.ط' : 'م²'}</small>` : '—');
const row = (l) => `<tr class="tk__row l-${l.layer}${l.service ? ' is-service' : ''}" role="row" data-part="${l.part}" data-layer="${l.layer}">
            <th scope="row" role="rowheader"><button class="tk__pick" type="button" aria-pressed="false"><b>${esc(l.name)}</b><small>${esc(l.where)}</small></button></th>
            <td role="cell" class="nums" data-k="المقاس">${fmt(l.measured)} ${unitOf(l)}</td>
            <td role="cell" class="nums" data-k="الهدر">${l.waste ? `+${l.waste}%` : '—'}</td>
            <td role="cell" class="nums" data-k="العبوات"><span>${packs(l)}</span></td>
            <td role="cell" class="nums" data-k="الكمية × السعر"><span>${esc(l.qty)}<small>× ${fmt(l.price)}</small></span></td>
            <td role="cell" class="nums tk__amt" data-k="المبلغ">${fmt(l.amount)}</td>
          </tr>`;
const order = `<section class="band forder" id="order" aria-labelledby="order-title">
  <div class="wrap">
    ${head('order', o.title, o.lede)}
    <div class="seg flayers" role="group" aria-label="${esc(o.layerLabel)}">${o.layers.map((x, i) => `<button class="seg__opt" type="button" data-layer="${x.key}" aria-pressed="${i === 0}">${esc(x.label)}</button>`).join('')}</div>
    <div class="forder__body" data-layer="all">
      <figure class="fplan">
        ${planSvg}
        <ul class="fp__legend">${legend}</ul>
        <figcaption class="fplan__cap">${esc(o.planCaption)}</figcaption>
      </figure>
      <div class="tk" data-ui>
        <div class="tk__head"><span><b>${esc(o.docTitle)}</b><span class="nums">${esc(o.quoteRef)}</span></span><span class="tk__to">${esc(o.client)}<small class="nums">${esc(o.ref)}، ${esc(o.branch)}</small></span></div>
        <div class="tk__scroll">
        <table class="tk__table" role="table">
          <caption class="sr">بنود عرض السعر: المساحة المقيسة، ونسبة الهدر، والعبوات، والكمية المباعة، والمبلغ</caption>
          <thead role="rowgroup"><tr role="row">${['البند', 'المقاس', 'الهدر', 'العبوات', 'الكمية × السعر', 'المبلغ'].map((t) => `<th scope="col" role="columnheader">${t}</th>`).join('')}</tr></thead>
          <tbody role="rowgroup">
          ${o.lines.map(row).join('\n          ')}
          </tbody>
        </table>
        </div>
        <p class="tk__waste">${icon('i-ruler')}${esc(o.wasteNote)}</p>
        <dl class="tk__sum nums">
          <div><dt>المجموع</dt><dd>${fmt(sub)}</dd></div>
          <div><dt>${esc(o.discountLabel)}</dt><dd dir="ltr">−${fmt(o.discount)}</dd></div>
          <div><dt>ضريبة القيمة المضافة 15%</dt><dd>${fmt(vat)}</dd></div>
          <div class="tk__total"><dt>الإجمالي شامل الضريبة</dt><dd>${fmt(total)} ر.س</dd></div>
        </dl>
        <div class="tk__plan">
          <p><b>${esc(o.planTitle)}</b></p>
          <ol class="ruler">${o.payments.map((p, i) => `<li style="--w:${p.pct}"><span class="ruler__pct nums">${p.pct}%</span><span class="ruler__when">${esc(p.when)}</span><span class="ruler__amt nums">${fmt(payAmts[i])}</span></li>`).join('')}</ol>
        </div>
        <p class="tk__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;

/* ---------- pains: a ledger of what happens, what the system does, and where ---------- */
const p = d.pains;
const pains = `<section class="band fpains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    ${head('pains', p.title, p.lede)}
    <table class="ledger" role="table" aria-labelledby="pains-title">
      <thead role="rowgroup"><tr role="row">${p.cols.map((c) => `<th scope="col" role="columnheader">${esc(c)}</th>`).join('')}</tr></thead>
      <tbody role="rowgroup">
${p.items.map((x, i) => `        <tr class="ledger__row" role="row"><th scope="row" role="rowheader" class="ledger__pain"><span class="ledger__n nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span>${esc(x.pain)}</th><td role="cell" class="ledger__fix">${esc(x.fix)}</td><td role="cell" class="ledger__where"><span>${icon(x.icon)}${esc(x.where)}</span></td></tr>`).join('\n')}
      </tbody>
    </table>
  </div>
</section>`;

/* ---------- phases: the flow, with the installation stages and the dues that fall on them ---------- */
const g = d.config;
const duesAt = (s) => (s.pay === undefined ? '' : `<p class="flow__due">${icon('i-hand-coins')}<span>تُستحق <b class="nums">${o.payments[s.pay].pct}%</b> ${esc(o.payments[s.pay].when)}</span><b class="nums">${fmt(payAmts[s.pay])}</b></p>`);
const lastDue = o.payments.length - 1;
const phases = `<section class="band fphases" id="phases" aria-labelledby="phases-title">
  <div class="wrap">
    ${head('phases', g.title, g.lede)}
    <div class="fphases__body">
      <div class="flow">
        <h3>${icon('i-workflow')}${esc(g.stagesTitle)}</h3>
        <ol class="flow__list">
${g.stages.map((s, i) => `          <li class="flow__stage${s.phase ? ' is-phase' : ''}">
            <span class="flow__n nums" aria-hidden="true">${i + 1}</span>
            <div class="flow__body"><p class="flow__name"><b>${esc(s.name)}</b>${s.phase ? `<span class="flow__tag">${esc(g.phaseTag)}</span>` : ''}<span class="flow__days nums">${esc(s.days)}</span></p><p class="flow__does">${esc(s.does)}</p>${duesAt(s)}${i === g.stages.length - 1 ? `<p class="flow__due">${icon('i-hand-coins')}<span>تُستحق <b class="nums">${o.payments[lastDue].pct}%</b> ${esc(o.payments[lastDue].when)}</span><b class="nums">${fmt(payAmts[lastDue])}</b></p>` : ''}</div>
          </li>`).join('\n')}
        </ol>
        <p class="flow__note">${esc(g.daysNote)}</p>
      </div>
      <aside class="fside">
        <div class="fside__block">
          <h3>${icon('i-wallet')}${esc(g.planTitle)}</h3>
          <ul class="plans">${g.plans.map((pl) => `<li><b class="nums"><bdi dir="ltr">${esc(pl.name)}</bdi></b><ol class="plans__bar">${pl.parts.map((x) => `<li style="--w:${x.pct}"><span class="nums">${x.pct}%</span><small>${esc(x.when)}</small></li>`).join('')}</ol></li>`).join('')}</ul>
          <p class="fside__note">${esc(g.planNote)}</p>
        </div>
        <div class="fside__block">
          <h3>${icon('i-file-text')}${esc(g.clausesTitle)}</h3>
          <ul class="fclauses">${g.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ul>
        </div>
      </aside>
    </div>
    <p class="fphases__edit">${icon('i-user-cog')}${esc(g.editable)}</p>
    <p class="fphases__bridge">${esc(g.bridge)}</p>
  </div>
</section>`;

/* ---------- channels: one row each, picture and text alternating ---------- */
const c = d.channels;
const channels = `<section class="band fchan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    ${head('channels', c.title, c.lede)}
    <ol class="zig">
${c.items.map((x, i) => `      <li class="zig__row">
        <figure class="zig__pic"><img src="${esc(x.image.src)}"${x.image.srcset ? ` srcset="${esc(x.image.srcset)}" sizes="(max-width: 760px) 100vw, 50vw"` : ''} width="${x.image.w}" height="${x.image.h}" alt="${esc(x.image.alt)}" loading="lazy" decoding="async"></figure>
        <div class="zig__text"><span class="zig__n nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span><h3>${icon(x.icon)}${esc(x.title)}</h3><p>${esc(x.text)}</p></div>
      </li>`).join('\n')}
    </ol>
    <p class="zig__merge"><span class="nums">${esc(o.ref)}</span>القنوات الثلاث تفتح الطلب نفسه، وتقرير المبيعات يفصل كل قناة وكل بائع.</p>
  </div>
</section>`;

/* ---------- roles: a roster, one line per person ---------- */
const rl = d.roles;
const roles = `<section class="band froles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    ${head('roles', rl.title, rl.lede)}
    <ul class="roster">
${rl.items.map((x) => `      <li class="roster__row"><span class="roster__av" aria-hidden="true">${esc(x.letter)}</span><h3 class="roster__name">${esc(x.name)}<small>${esc(x.who)}</small></h3><ul class="roster__does">${x.does.map((y) => `<li>${esc(y)}</li>`).join('')}</ul></li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- FAQ: every answer open, numbered, in two columns ---------- */
const faq = `<section class="band ffaq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    ${head('faq', d.faqTitle, '')}
    <dl class="fqa">
${d.faq.map((f, i) => `      <div class="fqa__item"><dt><span class="fqa__n nums" aria-hidden="true">${String(i + 1).padStart(2, '0')}</span>${esc(f.q)}</dt><dd>${esc(f.a)}</dd></div>`).join('\n')}
    </dl>
  </div>
</section>`;

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, phases, channels, roles, faq];
export const styles = ['page.css'];
export const scripts = ['page.js'];
console.log(`flooring-walls-ceilings: subtotal ${fmt(sub)}, net ${fmt(net)}, VAT ${fmt(vat)}, total ${fmt(total)}, dues ${payAmts.map(fmt).join(' + ')}`);
