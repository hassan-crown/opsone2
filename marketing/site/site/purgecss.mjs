// Per-page CSS for the build: keeps only the rules whose selectors can match a page, judged against a "corpus" (the
// page's HTML plus the JavaScript it loads, where state classes such as is-open or has-pick are written).
// Conservative by design: a selector is dropped only when one of its class or id names appears nowhere in the corpus.
// Arguments of :not(), :is(), :where(), :has() and attribute selectors never cause a drop. @font-face, @keyframes and
// rules without a class or id (tags, :root) are always kept. Input is minified CSS (site/mincss.mjs).

// split at top-level separators (outside (), [], strings)
const splitTop = (s, sep) => {
  const out = []; let depth = 0, q = '', cur = '';
  for (const ch of s) {
    if (q) { cur += ch; if (ch === q) q = ''; continue; }
    if (ch === '"' || ch === "'") { q = ch; cur += ch; continue; }
    if (ch === '(' || ch === '[') depth++;
    if (ch === ')' || ch === ']') depth--;
    if (ch === sep && depth === 0) { out.push(cur); cur = ''; continue; }
    cur += ch;
  }
  out.push(cur);
  return out;
};

// blocks: [{ prelude, body }] where body is a declaration string or nested blocks (for @media/@supports)
function parse(css) {
  const blocks = []; let i = 0;
  while (i < css.length) {
    const open = css.indexOf('{', i);
    if (open < 0) break;
    const prelude = css.slice(i, open).trim();
    let depth = 1, j = open + 1, q = '';
    for (; j < css.length && depth; j++) {
      const ch = css[j];
      if (q) { if (ch === q && css[j - 1] !== '\\') q = ''; continue; }
      if (ch === '"' || ch === "'") q = ch;
      else if (ch === '{') depth++;
      else if (ch === '}') depth--;
    }
    const inner = css.slice(open + 1, j - 1);
    if (/^@(media|supports|layer|container)/.test(prelude)) blocks.push({ prelude, children: parse(inner) });
    else blocks.push({ prelude, body: inner });
    i = j;
  }
  return blocks;
}

const stripArgs = (sel) => {
  // drop the contents of attribute selectors and of functional pseudo-classes
  let out = '', depth = 0, sq = 0;
  for (let k = 0; k < sel.length; k++) {
    const ch = sel[k];
    if (ch === '[') { sq++; continue; }
    if (ch === ']') { sq--; continue; }
    if (sq) continue;
    if (ch === '(') { depth++; continue; }
    if (ch === ')') { depth--; continue; }
    if (!depth) out += ch;
  }
  return out;
};

export function makeMatcher(corpus) {
  const cache = new Map();
  const has = (name) => {
    if (cache.has(name)) return cache.get(name);
    const esc = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    let ok = new RegExp(`(^|[^\\w-])${esc}([^\\w-]|$)`).test(corpus);
    // names built at run time from a prefix: `pill-s--${kind}`, 'is-' + state
    for (let k = 0; !ok && k < name.length; k++) {
      if (name[k] !== '-') continue;
      const pre = name.slice(0, k + 1);
      ok = corpus.includes(pre + '${') || corpus.includes(pre + "' +") || corpus.includes(pre + "'+") || corpus.includes(pre + '" +') || corpus.includes(pre + '"+');
    }
    cache.set(name, ok);
    return ok;
  };
  return (selector) => {
    const bare = stripArgs(selector);
    const names = [...bare.matchAll(/[.#](-?[A-Za-z_][\w-]*)/g)].map((m) => m[1]);
    return names.every(has);
  };
}

export function purge(css, corpus) {
  const matches = makeMatcher(corpus);
  const emit = (blocks) => blocks.map((b) => {
    if (b.children) { const inner = emit(b.children); return inner ? `${b.prelude}{${inner}}` : ''; }
    if (b.prelude.startsWith('@')) return `${b.prelude}{${b.body}}`; // @font-face, @keyframes, @page …
    const kept = splitTop(b.prelude, ',').filter((s) => matches(s.trim()));
    return kept.length ? `${kept.join(',')}{${b.body}}` : '';
  }).join('');
  return emit(parse(css));
}
