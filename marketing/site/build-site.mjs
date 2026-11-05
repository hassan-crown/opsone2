#!/usr/bin/env node
// Builds the whole marketing site: node marketing/site/build-site.mjs
// 1. Writes the shared header, footer and sector cards (site/partials.mjs) into index.html between its
//    <!-- @header --> … <!-- /@header --> markers (also @footer, @solutions). The rest of index.html is hand-written.
// 2. Builds every sector page listed in site/sectors.json (solutions/build.mjs <slug>).
// 3. Writes the plain pages (site/pages.mjs): privacy, terms and cookies drafts, thanks, 404.
// 4. Per page CSS: the rules the page can use (site/purgecss.mjs); those of the header, hero and fixed layers go
//    inline in <head>, the rest load without blocking from <page>/page.bundle.min.css (site.js switches it on;
//    <noscript> links it for visitors without JavaScript). Pages without a hero inline all of their CSS.
// 5. Writes sitemap.xml: the home page and every sector page, lastmod = the last commit that touched the page's
//    sources, or today while they have uncommitted changes.
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { header, footer, consent, pricing, solutionsGrid, site, sectors } from './site/partials.mjs';
import { pages as plainPages, render } from './site/pages.mjs';
import { minifyCss } from './site/mincss.mjs';
import { purge } from './site/purgecss.mjs';
import { readdirSync } from 'node:fs';
import { mkdirSync } from 'node:fs';

const here = dirname(fileURLToPath(import.meta.url));

/* ---------- 1. the home page's shared pieces ---------- */
const homeFile = join(here, 'index.html');
let home = readFileSync(homeFile, 'utf8');
const put = (name, html) => {
  const open = `<!-- @${name} -->`, close = `<!-- /@${name} -->`;
  const i = home.indexOf(open), j = home.indexOf(close);
  if (i < 0 || j < i) throw new Error(`index.html: missing ${open} … ${close}`);
  home = home.slice(0, i + open.length) + '\n' + html + '\n' + home.slice(j);
};
put('header', header({ root: '' }));
put('footer', footer({ root: '' }));
put('solutions', `    ${solutionsGrid('')}`);
put('consent', consent({ root: '' }));
put('pricing', pricing());
// icons come from one cached file (assets/icons.svg), not an inline sprite on every page
home = home.split('href="#i-').join('href="assets/icons.svg#i-');
writeFileSync(homeFile, home);
console.log('index.html: header, footer and sector cards written');

/* ---------- minified stylesheets: every page links *.min.css; the readable *.css stay the source ---------- */
const cssFiles = ['styles.css', 'solutions/sector.css', ...sectors.flatMap((s) => readdirSync(join(here, 'solutions', s.slug)).filter((f) => f.endsWith('.css') && !f.endsWith('.min.css')).map((f) => `solutions/${s.slug}/${f}`))];
for (const f of cssFiles) writeFileSync(join(here, f.replace(/\.css$/, '.min.css')), minifyCss(readFileSync(join(here, f), 'utf8')));
console.log(`css: ${cssFiles.length} files minified`);

/* ---------- 2. sector pages ---------- */
for (const s of sectors) execFileSync(process.execPath, [join(here, 'solutions', 'build.mjs'), s.slug], { stdio: 'inherit' });

/* ---------- 3. plain pages: privacy, terms, cookies (drafts), thanks, 404 ---------- */
for (const p of plainPages) {
  const file = p.path.endsWith('/') ? join(here, p.path, 'index.html') : join(here, p.path);
  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(file, render(p));
}
console.log(`pages: ${plainPages.map((p) => p.path).join(', ')}`);

/* ---------- 4. per-page CSS: critical inline, the rest non-blocking ---------- */
const toPosix = (p) => p.split('\\').join('/');
// one page's stylesheets, read in order, with url() rewritten to be relative to the page's folder
const cssFor = (pageFile, hrefs) => hrefs.map((h) => {
  const file = h.startsWith('/') ? join(here, h) : resolve(dirname(pageFile), h);
  return readFileSync(file, 'utf8').replace(/url\((['"]?)([^'")]+)\1\)/g, (m, q, u) => {
    if (/^(data:|https?:|\/|#)/.test(u)) return m;
    return `url(${q}${toPosix(relative(dirname(pageFile), resolve(dirname(file), u)))}${q})`;
  });
}).join('');
const scriptsOf = (pageFile, html) => [...html.matchAll(/<script src="([^"]+)"/g)].map((m) => m[1])
  .map((src) => (src.startsWith('/') ? join(here, src) : resolve(dirname(pageFile), src))).filter(existsSync).map((f) => readFileSync(f, 'utf8')).join('\n');
// the first screen: everything up to the end of the section after the hero, plus the fixed layers (consent, WhatsApp button)
const firstScreen = (html) => {
  const hero = html.indexOf('<section class="hero');
  // the hero and the section after it: on a wide screen the next section starts inside the first 900px
  const heroEnd = hero >= 0 ? html.indexOf('</section>', hero) : -1;
  const next = heroEnd >= 0 ? html.indexOf('<section', heroEnd) : -1;
  const end = next >= 0 ? html.indexOf('</section>', next) : hero >= 0 ? heroEnd : html.indexOf('</main>');
  const fixed = html.indexOf('<div class="consent');
  return html.slice(0, end) + (fixed >= 0 ? html.slice(fixed) : '') + html.slice(html.indexOf('<a class="wa-float'), html.indexOf('</a>', html.indexOf('<a class="wa-float')) + 4);
};
const styleTags = (pageFile, html, hrefs, inlineAll) => {
  const js = scriptsOf(pageFile, html);
  const all = cssFor(pageFile, hrefs);
  const used = purge(all, html + js);
  if (inlineAll) return `<style>${used}</style>`;
  const bundle = join(dirname(pageFile), 'page.bundle.min.css');
  writeFileSync(bundle, used + '\n');
  // the first screen's own markup, plus only the state classes the scripts toggle (is-*, has-*): a class that JS
  // merely mentions belongs to a later section and would arrive here without its container's rules
  const states = [...new Set(js.match(/\b(?:is|has)-[a-z][\w-]*/g) || [])].join(' ');
  // the hero's own script block (sector.js renders the station screens from a template) belongs to the first screen too
  const heroJs = (js.match(/\/\* -+ hero[\s\S]*?(?=\/\* -+ )/) || [''])[0];
  const crit = purge(all, firstScreen(html) + heroJs + ' class="' + states + '"');
  return `<style>${crit}</style>\n<link rel="stylesheet" href="page.bundle.min.css" media="print" data-async-css>\n<noscript><link rel="stylesheet" href="page.bundle.min.css"></noscript>`;
};
const linkRe = /(?:<link rel="stylesheet" href="[^"]+">\n?)+/;
const cssPages = [...sectors.map((s) => join(here, 'solutions', s.slug, 'index.html')), ...plainPages.map((p) => join(here, p.path.endsWith('/') ? join(p.path, 'index.html') : p.path))];
for (const file of cssPages) {
  let html = readFileSync(file, 'utf8');
  const links = html.match(linkRe);
  if (!links) throw new Error(`${file}: no stylesheet links`);
  const hrefs = [...links[0].matchAll(/href="([^"]+)"/g)].map((m) => m[1]);
  // pages without a hero (legal pages, thanks, 404) are short: all their CSS goes inline
  const short = !html.includes('<section class="hero');
  html = html.replace(links[0], styleTags(file, html, hrefs, short) + '\n');
  writeFileSync(file, html);
}
// the home page keeps its source list in the marker: <!-- @css styles.min.css --> … <!-- /@css -->
{
  const file = join(here, 'index.html');
  let html = readFileSync(file, 'utf8');
  const open = html.match(/<!-- @css ([^>]+?) -->/), close = '<!-- /@css -->';
  if (!open || html.indexOf(close) < open.index) throw new Error('index.html: missing <!-- @css … --> marker');
  const start = open.index + open[0].length, end = html.indexOf(close);
  html = html.slice(0, start) + '\n' + styleTags(file, html.slice(0, start) + html.slice(end), open[1].split(/\s+/), false) + '\n' + html.slice(end);
  writeFileSync(file, html);
}
console.log(`css: critical inline and page bundles for ${cssPages.length + 1} pages`);

/* ---------- 5. sitemap ---------- */
const today = new Date().toISOString().slice(0, 10);
const git = (args) => { try { return execFileSync('git', args, { cwd: here, encoding: 'utf8' }).trim(); } catch { return ''; } };
const lastmod = (paths) => (git(['status', '--porcelain', '--', ...paths]) ? today : git(['log', '-1', '--format=%cs', '--', ...paths]) || today);
const shared = ['site', 'styles.css', 'solutions/build.mjs', 'solutions/sector.css', 'solutions/sector.js'];
const pages = [
  { loc: site.origin, paths: ['index.html', 'styles.css', 'main.js', 'site'] },
  ...sectors.map((s) => ({ loc: `${site.origin}solutions/${s.slug}/`, paths: [`solutions/${s.slug}`, ...shared] })),
  // legal pages join the sitemap once reviewed (draft: false in site/pages.mjs)
  ...plainPages.filter((p) => !p.draft && !p.noindex).map((p) => ({ loc: `${site.origin}${p.path}`, paths: ['site/pages.mjs'] })),
];
const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
${pages.map((p) => `  <url>
    <loc>${p.loc}</loc>
    <xhtml:link rel="alternate" hreflang="ar" href="${p.loc}"/>
    <lastmod>${lastmod(p.paths)}</lastmod>
  </url>`).join('\n')}
</urlset>
`;
writeFileSync(join(here, 'sitemap.xml'), xml);
console.log(`sitemap.xml: ${pages.length} URLs`);
