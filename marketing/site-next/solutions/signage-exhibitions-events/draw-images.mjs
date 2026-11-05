import { writeFileSync, mkdirSync } from 'node:fs';
// Draws the page's image slots as plain SVG: node draw-images.mjs <out-dir>. Each file can be swapped for a photograph later by changing its src in data.json.
const out = process.argv[2]; mkdirSync(out, { recursive: true });
const INK = '#0B1B33', TEAL = '#0E7C72', T50 = '#E3F1EF', FILL = '#F5F7F9', RULE = '#DDE3EA', MUTED = '#66768E', W = '#FFFFFF';
const f = (n) => Math.round(n * 10) / 10;

/* ---------- hero: an isometric booth, 6 × 4 m, open on two sides ---------- */
{
  const s = 112, ox = 720, oy = 330;
  const P = (x, y, z = 0) => [ox + (x - y) * s * 0.866, oy + (x + y) * s * 0.5 - z * s];
  const poly = (pts, attrs) => `<polygon points="${pts.map((p) => P(...p).map(f).join(',')).join(' ')}" ${attrs}/>`;
  const line = (a, b, attrs) => { const [x1, y1] = P(...a), [x2, y2] = P(...b); return `<line x1="${f(x1)}" y1="${f(y1)}" x2="${f(x2)}" y2="${f(y2)}" ${attrs}/>`; };
  const H = 2.5, FH = 0.7; // wall height, fascia height
  let g = '';
  // hall floor grid
  for (let i = -3; i <= 9; i++) g += line([i, -3], [i, 7], `stroke="${RULE}" stroke-width="1"`);
  for (let j = -3; j <= 7; j++) g += line([-3, j], [9, j], `stroke="${RULE}" stroke-width="1"`);
  // platform (raised floor 10 cm)
  const z0 = 0.1;
  g += poly([[0, 4, 0], [6, 4, 0], [6, 4, z0], [0, 4, z0]], `fill="#E9EDF2" stroke="${INK}" stroke-width="2"`);
  g += poly([[6, 0, 0], [6, 4, 0], [6, 4, z0], [6, 0, z0]], `fill="#D9DFE7" stroke="${INK}" stroke-width="2"`);
  g += poly([[0, 0, z0], [6, 0, z0], [6, 4, z0], [0, 4, z0]], `fill="${W}" stroke="${INK}" stroke-width="2"`);
  // carpet tiles hint
  for (let i = 1; i < 6; i++) g += line([i, 0, z0], [i, 4, z0], `stroke="${RULE}" stroke-width="1"`);
  for (let j = 1; j < 4; j++) g += line([0, j, z0], [6, j, z0], `stroke="${RULE}" stroke-width="1"`);
  // back wall (along x at y=0) and side wall (along y at x=0), 12 cm thick shown as faces
  const t = 0.12;
  g += poly([[0, 0, z0], [6, 0, z0], [6, 0, H], [0, 0, H]], `fill="${FILL}" stroke="${INK}" stroke-width="2"`); // inner face of back wall (facing +y)
  g += poly([[0, 0, z0], [0, 4, z0], [0, 4, H], [0, 0, H]], `fill="#EEF1F5" stroke="${INK}" stroke-width="2"`); // inner face of side wall
  g += poly([[6, 0, z0], [6, t * -1, z0], [6, -t, H], [6, 0, H]], `fill="${INK}"`);
  g += poly([[0, 4, z0], [-t, 4, z0], [-t, 4, H], [0, 4, H]], `fill="${INK}"`);
  // graphic wall print on back wall: teal panels
  g += poly([[0.6, 0, 0.5], [4.2, 0, 0.5], [4.2, 0, 2.2], [0.6, 0, 2.2]], `fill="${T50}" stroke="${TEAL}" stroke-width="2"`);
  for (let k = 0; k < 4; k++) g += line([0.9, 0, 1.9 - k * 0.3], [0.9 + (k ? 2.4 - k * 0.4 : 3), 0, 1.9 - k * 0.3], `stroke="${TEAL}" stroke-width="${k ? 6 : 12}" stroke-linecap="round" opacity="${k ? 0.55 : 1}"`);
  // screen on side wall
  g += poly([[0, 1.2, 1.1], [0, 2.6, 1.1], [0, 2.6, 1.9], [0, 1.2, 1.9]], `fill="${INK}" stroke="${INK}" stroke-width="2"`);
  g += poly([[0, 1.3, 1.18], [0, 2.5, 1.18], [0, 2.5, 1.82], [0, 1.3, 1.82]], `fill="#1E3A5C"`);
  g += line([0, 1.45, 1.5], [0, 2.2, 1.5], `stroke="${TEAL}" stroke-width="4" opacity="0.9"`);
  // fascia header above back wall with lit letter blocks
  g += poly([[0, 0, H], [6, 0, H], [6, 0, H + FH], [0, 0, H + FH]], `fill="${INK}" stroke="${INK}" stroke-width="2"`);
  g += poly([[0, 0, H + FH], [6, 0, H + FH], [6, -t, H + FH], [0, -t, H + FH]], `fill="#24364F"`);
  const letters = [[0.7, 0.9], [1.75, 0.55], [2.45, 0.9], [3.5, 0.45], [4.1, 1.2]];
  letters.forEach(([x, w]) => {
    g += poly([[x, 0, H + 0.18], [x + w, 0, H + 0.18], [x + w, 0, H + 0.52], [x, 0, H + 0.52]], `fill="${TEAL}" filter="url(#glow)"`);
    g += poly([[x, 0, H + 0.18], [x + w, 0, H + 0.18], [x + w, 0, H + 0.52], [x, 0, H + 0.52]], `fill="#7FD6CB" opacity="0.55"`);
  });
  // counter near the open corner
  const cx = 4.0, cy = 2.6, cw = 1.4, cd = 0.6, ch = 1.05;
  g += poly([[cx, cy + cd, z0], [cx + cw, cy + cd, z0], [cx + cw, cy + cd, ch], [cx, cy + cd, ch]], `fill="${W}" stroke="${INK}" stroke-width="2"`);
  g += poly([[cx + cw, cy, z0], [cx + cw, cy + cd, z0], [cx + cw, cy + cd, ch], [cx + cw, cy, ch]], `fill="#E9EDF2" stroke="${INK}" stroke-width="2"`);
  g += poly([[cx - 0.05, cy - 0.05, ch], [cx + cw + 0.05, cy - 0.05, ch], [cx + cw + 0.05, cy + cd + 0.05, ch], [cx - 0.05, cy + cd + 0.05, ch]], `fill="${TEAL}" stroke="${INK}" stroke-width="2"`);
  g += poly([[cx + 0.35, cy + cd, 0.45], [cx + 1.05, cy + cd, 0.45], [cx + 1.05, cy + cd, 0.75], [cx + 0.35, cy + cd, 0.75]], `fill="${T50}" stroke="${TEAL}" stroke-width="2"`);
  // light arms on fascia
  [1.2, 3, 4.8].forEach((x) => { g += line([x, 0, H + 0.02], [x, 0.45, H - 0.05], `stroke="${INK}" stroke-width="3"`); const [lx, ly] = P(x, 0.45, H - 0.05); g += `<circle cx="${f(lx)}" cy="${f(ly)}" r="7" fill="${INK}"/><polygon points="${f(lx - 6)},${f(ly + 4)} ${f(lx + 6)},${f(ly + 4)} ${f(lx + 34)},${f(ly + 120)} ${f(lx - 34)},${f(ly + 120)}" fill="${TEAL}" opacity="0.07"/>`; });
  // dimension lines (front edges), in centimetres
  const dim = (a, b, off, label, rot) => {
    const [x1, y1] = P(...a), [x2, y2] = P(...b), mx = (x1 + x2) / 2 + off[0], my = (y1 + y2) / 2 + off[1];
    return `<line x1="${f(x1 + off[0])}" y1="${f(y1 + off[1])}" x2="${f(x2 + off[0])}" y2="${f(y2 + off[1])}" stroke="${MUTED}" stroke-width="1.5"/><circle cx="${f(x1 + off[0])}" cy="${f(y1 + off[1])}" r="3.5" fill="${MUTED}"/><circle cx="${f(x2 + off[0])}" cy="${f(y2 + off[1])}" r="3.5" fill="${MUTED}"/><g transform="translate(${f(mx)} ${f(my)}) rotate(${rot})"><rect x="-34" y="-15" width="68" height="30" rx="6" fill="${W}" stroke="${RULE}"/><text x="0" y="6" text-anchor="middle" font-family="Helvetica, Arial, sans-serif" font-size="18" font-weight="700" fill="${INK}">${label}</text></g>`;
  };
  g += dim([0, 4, 0], [6, 4, 0], [-26, 44], '600', -30);
  g += dim([6, 0, 0], [6, 4, 0], [44, 26], '400', 30);
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1400 1050" width="1400" height="1050"><defs><filter id="glow" x="-50%" y="-80%" width="200%" height="260%"><feGaussianBlur stdDeviation="9"/></filter></defs><rect width="1400" height="1050" fill="${FILL}"/>${g}</svg>`;
  writeFileSync(`${out}/signage-hero.svg`, svg);
}

/* ---------- channel slots, 1000 × 750 ---------- */
const frame = (body) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 750" width="1000" height="750"><defs><filter id="glow" x="-30%" y="-80%" width="160%" height="260%"><feGaussianBlur stdDeviation="8"/></filter></defs><rect width="1000" height="750" fill="${FILL}"/>${body}</svg>`;
const txt = (x, y, s, size = 20, anchor = 'middle') => `<text x="${x}" y="${y}" text-anchor="${anchor}" font-family="Helvetica, Arial, sans-serif" font-size="${size}" font-weight="700" fill="${INK}">${s}</text>`;

// facade: a shopfront elevation, a lit fascia of letters above the entrance, its width dimensioned
{
  let b = `<rect x="140" y="170" width="720" height="500" fill="${W}" stroke="${INK}" stroke-width="3"/>`;
  b += `<rect x="140" y="170" width="720" height="130" fill="${INK}"/>`;
  [[210, 90], [320, 60], [400, 110], [530, 70], [620, 140], [780, 30]].forEach(([x, w]) => { b += `<rect x="${x}" y="212" width="${w}" height="46" rx="5" fill="${TEAL}" filter="url(#glow)"/><rect x="${x}" y="212" width="${w}" height="46" rx="5" fill="#8FDCD2"/>`; });
  b += `<rect x="180" y="340" width="260" height="330" fill="${T50}" stroke="${INK}" stroke-width="3"/><line x1="310" y1="340" x2="310" y2="670" stroke="${INK}" stroke-width="3"/>`;
  b += `<rect x="480" y="340" width="340" height="230" fill="${T50}" stroke="${INK}" stroke-width="3"/><line x1="480" y1="455" x2="820" y2="455" stroke="${INK}" stroke-width="2" opacity="0.4"/>`;
  b += `<line x1="100" y1="670" x2="900" y2="670" stroke="${INK}" stroke-width="4"/>`;
  b += `<g stroke="${MUTED}" stroke-width="2"><line x1="140" y1="120" x2="860" y2="120"/><line x1="140" y1="105" x2="140" y2="160"/><line x1="860" y1="105" x2="860" y2="160"/></g><rect x="450" y="102" width="100" height="36" rx="8" fill="${W}" stroke="${RULE}"/>${txt(500, 127, '720')}`;
  b += `<g stroke="${MUTED}" stroke-width="2"><line x1="900" y1="170" x2="900" y2="300"/><line x1="885" y1="170" x2="915" y2="170"/><line x1="885" y1="300" x2="915" y2="300"/></g><rect x="915" y="217" width="76" height="36" rx="8" fill="${W}" stroke="${RULE}"/>${txt(953, 242, '130')}`;
  writeFileSync(`${out}/signage-facade.svg`, frame(b));
}
// store: a roll-up stand and a standing banner, ready stock
{
  let b = `<line x1="80" y1="660" x2="920" y2="660" stroke="${RULE}" stroke-width="4"/>`;
  const rollup = (x, w, h, accent) => {
    const top = 660 - h;
    let r = `<rect x="${x}" y="${top}" width="${w}" height="${h - 30}" fill="${W}" stroke="${INK}" stroke-width="3"/>`;
    r += `<rect x="${x}" y="${top}" width="${w}" height="${h * 0.32}" fill="${accent}"/>`;
    r += `<rect x="${x + 30}" y="${top + h * 0.42}" width="${w - 60}" height="16" rx="8" fill="${INK}"/><rect x="${x + 30}" y="${top + h * 0.42 + 34}" width="${(w - 60) * 0.7}" height="12" rx="6" fill="${MUTED}" opacity="0.6"/><rect x="${x + 30}" y="${top + h * 0.42 + 58}" width="${(w - 60) * 0.55}" height="12" rx="6" fill="${MUTED}" opacity="0.6"/>`;
    r += `<rect x="${x + 30}" y="${top + h * 0.62}" width="${w - 60}" height="${h * 0.22}" rx="10" fill="${T50}" stroke="${TEAL}" stroke-width="2"/>`;
    r += `<rect x="${x - 14}" y="630" width="${w + 28}" height="30" rx="8" fill="${INK}"/><line x1="${x + w / 2}" y1="${top}" x2="${x + w / 2}" y2="630" stroke="${INK}" stroke-width="2" opacity="0.15"/>`;
    return r;
  };
  b += rollup(170, 230, 540, TEAL);
  b += rollup(470, 180, 460, INK);
  // a stack of boxed roll-ups
  [0, 1, 2].forEach((i) => { b += `<rect x="${700 + i * 8}" y="${560 - i * 62}" width="190" height="56" rx="6" fill="${W}" stroke="${INK}" stroke-width="3"/><line x1="${730 + i * 8}" y1="${588 - i * 62}" x2="${840 + i * 8}" y2="${588 - i * 62}" stroke="${TEAL}" stroke-width="6" stroke-linecap="round"/>`; });
  b += `<rect x="700" y="616" width="206" height="44" rx="6" fill="${W}" stroke="${INK}" stroke-width="3"/><line x1="730" y1="638" x2="850" y2="638" stroke="${TEAL}" stroke-width="6" stroke-linecap="round"/>`;
  writeFileSync(`${out}/signage-rollup.svg`, frame(b));
}
// survey: a tape measure laid across a facade bay, with the readings written beside it
{
  let b = `<rect x="120" y="140" width="760" height="470" fill="${W}" stroke="${INK}" stroke-width="3"/>`;
  b += `<rect x="120" y="140" width="760" height="120" fill="#E9EDF2" stroke="${INK}" stroke-width="3"/>`;
  b += `<rect x="170" y="300" width="300" height="310" fill="${T50}" stroke="${INK}" stroke-width="3"/><rect x="520" y="300" width="310" height="200" fill="${T50}" stroke="${INK}" stroke-width="3"/>`;
  // tape across the fascia band
  b += `<rect x="120" y="186" width="760" height="34" fill="#F2C94C" stroke="${INK}" stroke-width="2"/>`;
  for (let i = 0; i <= 76; i++) { const x = 120 + i * 10; b += `<line x1="${x}" y1="186" x2="${x}" y2="${i % 10 === 0 ? 210 : i % 5 === 0 ? 202 : 196}" stroke="${INK}" stroke-width="${i % 10 ? 1.2 : 2}"/>`; }
  b += `<rect x="826" y="170" width="96" height="66" rx="14" fill="${INK}"/><circle cx="874" cy="203" r="16" fill="none" stroke="${W}" stroke-width="4"/>`;
  // readings: a small note card
  b += `<g transform="translate(560 540)"><rect width="300" height="150" rx="14" fill="${W}" stroke="${INK}" stroke-width="3"/><circle cx="34" cy="40" r="8" fill="${TEAL}"/><rect x="56" y="32" width="150" height="16" rx="8" fill="${INK}"/>${txt(268, 47, '760', 22, 'end')}<circle cx="34" cy="84" r="8" fill="${TEAL}"/><rect x="56" y="76" width="120" height="16" rx="8" fill="${INK}"/>${txt(268, 91, '120', 22, 'end')}<circle cx="34" cy="124" r="8" fill="${RULE}"/><rect x="56" y="116" width="170" height="14" rx="7" fill="${MUTED}" opacity="0.5"/></g>`;
  writeFileSync(`${out}/signage-survey.svg`, frame(b));
}
console.log('drawn');
