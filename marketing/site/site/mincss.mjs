// A small CSS minifier for the build: drops comments and collapses whitespace outside strings and url(). It keeps every
// rule and value as written (no shorthand rewriting), so the minified file renders exactly like its source.
export function minifyCss(css) {
  // comments first: an apostrophe in a comment ("the page's") must not open a string
  css = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const out = [];
  // strings and url(...) pass through untouched; everything between them is compacted
  const re = /("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|url\([^)]*\))/g;
  let last = 0;
  const squeeze = (s) => s.replace(/\s+/g, ' ').replace(/\s*([{};,>])\s*/g, '$1').replace(/;}/g, '}');
  for (let m; (m = re.exec(css));) { out.push(squeeze(css.slice(last, m.index)), m[0]); last = m.index + m[0].length; }
  out.push(squeeze(css.slice(last)));
  return out.join('').trim() + '\n';
}
