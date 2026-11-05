// The custom-furniture page's own sections. Built by the shared builder: node marketing/site/solutions/build.mjs custom-furniture
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
const days = (n) => (n === 1 ? 'يوم' : n === 2 ? 'يومان' : n <= 10 ? `${n} أيام` : `${n} يومًا`);

/* ---------- the demo order: a board of the materials the customer picked, linked to the quote ---------- */
const o = d.order;
const sub = o.lines.reduce((a, l) => a + l.amount, 0);
const net = sub - (o.discount || 0), vat = Math.round(net * 0.15 * 100) / 100, total = Math.round((net + vat) * 100) / 100;
if (Math.abs(total - o.expectTotal) > 0.001) throw new Error(`order total ${total} ≠ ${o.expectTotal}`);
const mats = Object.fromEntries(o.materials.map((m) => [m.key, m]));
const items = (n) => (n === 1 ? 'بند واحد' : n === 2 ? 'بندان' : `<span class="nums">${n}</span> بنود`);
const groups = [...o.materials.map((m) => ({ mat: m, label: m.name, lines: o.lines.filter((l) => l.part === m.key) })), { mat: null, label: o.servicesLabel, lines: o.lines.filter((l) => !mats[l.part]) }]
  .filter((g) => g.lines.length).map((g) => ({ ...g, keys: [...new Set(g.lines.flatMap((l) => l.mats))], sum: g.lines.reduce((a, l) => a + l.amount, 0) }));
if (groups.reduce((a, g) => a + g.sum, 0) !== sub) throw new Error('every order line must sit in one material group');
const count = (k) => o.lines.filter((l) => l.mats.includes(k)).length;
const order = `<section class="band fo-order" id="order" aria-labelledby="order-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="order-title">${esc(o.title)}</h2><p class="lead">${esc(o.lede)}</p></div>
    <div class="fo-order__body">
      <div class="board">
        <p class="board__title"><b>${esc(o.boardTitle)}</b><span class="nums">${esc(o.ref)}</span></p>
        <ul class="board__swatches" role="group" aria-label="${esc(o.planLabel)}">
${o.materials.map((m) => `          <li><button class="sw" type="button" data-mat="${m.key}" aria-pressed="false"><span class="sw__chip sw__chip--${m.key}" aria-hidden="true"></span><span class="sw__code nums">${esc(m.code)}</span><b class="sw__name">${esc(m.name)}</b><small class="sw__kind">${esc(m.kind)}، ${items(count(m.key))}</small></button></li>`).join('\n')}
        </ul>
        <p class="board__note">${esc(o.boardNote)}</p>
        <p class="sr" aria-live="polite" id="board-live"></p>
      </div>
      <div class="spec" data-ui>
        <div class="spec__head"><span><b>${esc(o.docTitle)}</b> <span class="ref nums">${esc(o.quoteRef)}</span></span><span class="spec__to">${esc(o.client)}<small><bdi class="nums">${esc(o.ref)}</bdi>، ${esc(o.branch)}</small></span></div>
        <div class="sched">
${groups.map((g) => `          <section class="sched__group" data-mats="${g.keys.join(' ')}" aria-label="${esc(g.label)}">
            <h3 class="sched__head">${g.mat ? `<span class="sw__chip sw__chip--${g.mat.key}" aria-hidden="true"></span><span><b>${esc(g.mat.name)}</b><small><bdi class="nums">${esc(g.mat.code)}</bdi>، ${esc(g.mat.kind)}</small></span>` : `<span class="sw__chip sw__chip--none" aria-hidden="true"></span><span><b>${esc(o.servicesLabel)}</b><small>${esc(o.servicesNote)}</small></span>`}${g.lines.length > 1 ? `<span class="sched__sub nums">${fmt(g.sum)}</span>` : ''}</h3>
            <ul>${g.lines.map((l) => `<li class="spec__line" data-mats="${l.mats.join(' ')}"><span class="spec__name">${esc(l.name)}${l.mats.length > 1 ? `<small>مع ${l.mats.filter((k) => k !== g.mat?.key).map((k) => esc(mats[k].name)).join('، ')}</small>` : ''}</span><span class="spec__qty nums">${esc(l.qty)}</span><span class="spec__amt nums">${fmt(l.amount)}</span></li>`).join('')}</ul>
          </section>`).join('\n')}
        </div>
        <div class="spec__sum nums">
          <div><span>المجموع</span><span>${fmt(sub)}</span></div>
          <div><span>${esc(o.discountLabel)}</span><span dir="ltr">−${fmt(o.discount)}</span></div>
          <div><span>ضريبة القيمة المضافة 15%</span><span>${fmt(vat)}</span></div>
          <div class="spec__total"><span>الإجمالي شامل الضريبة</span><span>${fmt(total)} ر.س</span></div>
        </div>
        <div class="stubs">
          <b>${esc(o.planTitle)}</b>
          <ol>${o.payments.map((p, i) => `<li><span class="stubs__pct nums">${p.pct}%</span><span class="stubs__amt nums">${fmt(Math.round(total * p.pct) / 100)}</span><span class="stubs__when">${esc(p.when)}</span></li>`).join('')}</ol>
        </div>
        <p class="spec__note">${esc(o.note)}</p>
      </div>
    </div>
  </div>
</section>`;
const paid = o.payments.reduce((a, p) => a + Math.round(total * p.pct) / 100, 0);
if (Math.abs(paid - total) > 0.001) throw new Error(`payments ${paid} ≠ total ${total}`);

/* ---------- pains: hang tags, the pain on the front and the fix on the back ---------- */
const pains = `<section class="band fo-pains" id="pains" aria-labelledby="pains-title">
  <div class="wrap">
    <div class="sec-head fo-pains__head"><div><h2 class="head" id="pains-title">${esc(d.pains.title)}</h2><p class="lead">${esc(d.pains.lede)}</p></div><button class="btn btn--quiet tags__all" type="button" aria-pressed="false">اقلب البطاقات كلها</button></div>
    <ul class="tags">
${d.pains.items.map((p, i) => `      <li class="tag"><span class="tag__hole" aria-hidden="true"></span><div class="tag__card"><div class="tag__face tag__front"><p class="tag__pain">${esc(p.pain)}</p><button class="tag__flip" type="button" aria-expanded="false" aria-controls="fix-${i}">كيف يحلّه OpsOne ${icon('i-arrow', 'ic ic--dir')}</button></div><div class="tag__face tag__back" id="fix-${i}">${icon(p.icon, 'ic tag__ic')}<p class="tag__echo">${esc(p.pain)}</p><p>${esc(p.fix)}</p><button class="tag__flip tag__flip--back" type="button" aria-label="أعد البطاقة: ${esc(p.pain)}">المشكلة</button></div></div></li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- channels: three wardrobe doors; each opens on its channel ---------- */
const channels = `<section class="band fo-chan" id="channels" aria-labelledby="channels-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="channels-title">${esc(d.channels.title)}</h2><p class="lead">${esc(d.channels.lede)}</p></div>
    <ul class="robe">
${d.channels.items.map((c, i) => `      <li class="robe__bay${i === 0 ? ' is-open' : ''}"><div class="robe__inside" id="bay-${c.key}"><img src="${esc(c.image.src)}"${c.image.srcset ? ` srcset="${esc(c.image.srcset)}" sizes="(max-width: 760px) 100vw, 30vw"` : ''} width="${c.image.w}" height="${c.image.h}" alt="${esc(c.image.alt)}" loading="lazy" decoding="async"><h3>${icon(c.icon)}${esc(c.title)}</h3><p>${esc(c.text)}</p></div><button class="robe__door" type="button" aria-expanded="${i === 0}" aria-controls="bay-${c.key}"><span class="robe__panel" aria-hidden="true"></span><span class="robe__handle" aria-hidden="true"></span><span class="robe__label">${icon(c.icon)}<b>${esc(c.title)}</b><small>${i === 0 ? 'أغلق الباب' : 'افتح الباب'}</small></span></button></li>`).join('\n')}
    </ul>
  </div>
</section>`;

/* ---------- ready configuration: the flow as a ruler in days, the payment pins on it, clauses and a rule ---------- */
const cfg = d.config;
const tracks = cfg.tracks.map((t) => {
  const sum = t.stages.reduce((a, s) => a + s.days, 0);
  let at = 0;
  const edges = t.stages.map((s) => { const start = at; at += s.days; return { ...s, start, end: at }; });
  const pinAt = (key) => {
    if (key === 'first') return edges.find((s) => s.pay.includes('first')).end;
    if (key === 'second') return edges.find((s) => s.pay.includes('second')).start;
    return sum;
  };
  const pins = cfg.plans.map((p, pi) => `<ol class="tape__pins" data-plan="${pi}"${pi ? ' hidden' : ''}>${p.parts.map((x) => `<li style="--at:${(pinAt(x.key) / sum * 100).toFixed(3)}%"><b class="nums">${x.pct}%</b><span>${esc(x.when)}</span></li>`).join('')}</ol>`).join('');
  return { t, sum, html: `<div class="tape" data-track="${t.key}"${t.key === cfg.tracks[0].key ? '' : ' hidden'}>
        <div class="tape__bar" role="img" aria-label="${esc(t.label)}: ${t.stages.length} مراحل في ${days(sum)}">${edges.map((s, i) => `<span class="tape__seg" style="--d:${s.days}"><b class="nums">${i + 1}</b><small class="nums">${s.days}</small></span>`).join('')}</div>
        <div class="tape__ticks" style="--sum:${sum}" aria-hidden="true"></div>
        ${pins}
        <ol class="tape__list">${edges.map((s) => `<li><b>${esc(s.name)}</b> <span class="nums">${days(s.days)}</span></li>`).join('')}</ol>
        <p class="tape__total"><b class="nums">${days(sum)}</b> من الاحتياج إلى التركيب. ${esc(t.note)}</p>
      </div>` };
});
cfg.plans.forEach((p) => { const s = p.parts.reduce((a, x) => a + x.pct, 0); if (s !== 100) throw new Error(`plan ${p.name} sums to ${s}%`); });
const config = `<section class="band fo-cfg" id="setup" aria-labelledby="setup-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="setup-title">${esc(cfg.title)}</h2><p class="lead">${esc(cfg.lede)}</p></div>
    <div class="ruler" data-ui>
      <div class="ruler__controls">
        <div class="seg" role="group" aria-label="${esc(cfg.stagesTitle)}">${cfg.tracks.map((t, i) => `<button type="button" class="seg__opt" data-track="${t.key}" aria-pressed="${i === 0}">${esc(t.label)}</button>`).join('')}</div>
        <div class="seg seg--plan" role="group" aria-label="${esc(cfg.planTitle)}">${cfg.plans.map((p, i) => `<button type="button" class="seg__opt" data-plan="${i}" aria-pressed="${i === 0}"><bdi dir="ltr" class="nums">${esc(p.name)}</bdi></button>`).join('')}</div>
      </div>
      ${tracks.map((x) => x.html).join('\n      ')}
      <p class="ruler__src">${esc(cfg.planNote)}</p>
    </div>
    <div class="fo-cfg__more">
      <div class="label">
        <h3>${icon('i-file-text')}${esc(cfg.clausesTitle)}</h3>
        <ol>${cfg.clauses.map((c) => `<li>${esc(c)}</li>`).join('')}</ol>
      </div>
      <div class="rule">
        <h3>${icon('i-workflow')}قاعدة آلية</h3>
        <p class="rule__line"><span class="rule__k">عند</span>${esc(cfg.rule.when.replace(/^عند /, ''))}</p>
        <p class="rule__line"><span class="rule__k">وإذا</span>${esc(cfg.rule.if.replace(/^وإذا /, ''))}</p>
        <p class="rule__line"><span class="rule__k">فافعل</span>${esc(cfg.rule.then.replace(/^ف/, ''))}</p>
        <p class="rule__note">${esc(cfg.ruleNote)}</p>
      </div>
    </div>
    <p class="fo-cfg__edit">${icon('i-user-cog')}${esc(cfg.editable)} ${esc(cfg.bridge)}</p>
  </div>
</section>`;

/* ---------- roles: pick a role and see which parts of the order file it works on ---------- */
const r = d.roles;
const state = (role, k) => (role.hidden || []).includes(k) ? 'hidden' : role.on.includes(k) ? 'on' : 'off';
const stateText = { on: 'يعمل عليه', off: 'خارج عمله', hidden: 'مخفي عنه' };
const roles = `<section class="band fo-roles" id="roles" aria-labelledby="roles-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="roles-title">${esc(r.title)}</h2><p class="lead">${esc(r.lede)}</p></div>
    <div class="lens">
      <div class="lens__roles" role="group" aria-label="الأدوار">
${r.items.map((x, i) => `        <button class="lens__role" type="button" data-role="${i}" aria-pressed="${i === 0}"><span class="role__av" aria-hidden="true">${esc(x.letter)}</span><span><b>${esc(x.name)}</b><small>${esc(x.who)}</small></span></button>`).join('\n')}
      </div>
      <div class="lens__file" data-ui>
        <p class="lens__head"><b>ملف الطلب <bdi class="nums">${esc(o.ref)}</bdi></b><span id="lens-who" aria-live="polite">${esc(r.items[0].name)}</span></p>
${r.items.map((x, i) => `        <div class="lens__view" data-role="${i}"${i ? ' hidden' : ''}>
          <ul class="lens__rows">${r.file.map((f) => { const s = state(x, f.key); return `<li class="lens__row is-${s}"><span>${esc(f.label)}</span><span class="lens__state">${s === 'hidden' ? icon('i-eye-off') : s === 'on' ? icon('i-check') : ''}${stateText[s]}</span></li>`; }).join('')}</ul>
          <ul class="lens__does">${x.does.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>
        </div>`).join('\n')}
      </div>
    </div>
  </div>
</section>`;

/* ---------- FAQ: an index grouped by when the question comes up; every answer is visible ---------- */
const faq = `<section class="band fo-faq" id="faq" aria-labelledby="faq-title">
  <div class="wrap">
    <div class="sec-head"><h2 class="head" id="faq-title">${esc(d.faqTitle)}</h2></div>
    <div class="index">
${d.faqGroups.map((g) => `      <section class="index__group" aria-label="${esc(g.title)}"><h3>${esc(g.title)}</h3><dl>${g.ids.map((id) => `<div class="index__qa"><dt>${esc(d.faq[id].q)}</dt><dd>${esc(d.faq[id].a)}</dd></div>`).join('')}</dl></section>`).join('\n')}
    </div>
  </div>
</section>`;
const used = d.faqGroups.flatMap((g) => g.ids).sort((a, b) => a - b);
if (used.length !== d.faq.length || used.some((v, i) => v !== i)) throw new Error('every FAQ entry must be in exactly one group');

/* ---------- what build.mjs puts in place of the template's sections ---------- */
export const sections = [order, pains, channels, config, roles, faq];
export const styles = ['furniture.css'];
export const scripts = ['furniture.js'];
console.log(`custom-furniture: total ${fmt(total)}, VAT ${fmt(vat)}`);
