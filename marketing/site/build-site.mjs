#!/usr/bin/env node
// Builds the whole marketing site: node marketing/site/build-site.mjs
// 1. Writes the shared header, footer and sector cards (site/partials.mjs) into index.html between its
//    <!-- @header --> … <!-- /@header --> markers (also @footer, @solutions). The rest of index.html is hand-written.
// 2. Builds every sector page listed in site/sectors.json (solutions/build.mjs <slug>).
// 3. Writes the plain pages (site/pages.mjs): privacy, terms and cookies drafts, thanks, 404.
// 4. Writes sitemap.xml: the home page and every sector page, lastmod = the last commit that touched the page's
//    sources, or today while they have uncommitted changes.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { header, footer, consent, pricing, solutionsGrid, site, sectors } from './site/partials.mjs';
import { pages as plainPages, render } from './site/pages.mjs';
import { minifyCss } from './site/mincss.mjs';
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

/* ---------- 4. sitemap ---------- */
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
