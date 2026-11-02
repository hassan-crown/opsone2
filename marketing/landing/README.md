# OpsOne landing page

A self-contained static page (HTML, CSS, JS; no build step; fonts and pictures local) that sells OpsOne to owners
and managers of Saudi businesses that make to order and install. Arabic only, right to left. One action: request a demo.
The folder choice is recorded in `docs/decisions/0034-marketing-landing-folder.md`.

## Open it locally

Open `marketing/landing/index.html` directly in a browser, or serve the folder:

```bash
python3 -m http.server 8080 --directory marketing/landing
```

then visit `http://localhost:8080`.

## Files

| File | What it is |
| --- | --- |
| `index.html` | The page. |
| `styles.css` | Brand tokens (from `docs/design-system/source/tokens.json`), layout and motion. |
| `main.js` | The live order path, the people tabs, the demo form, the reveals and the scroll-linked diagram. |
| `config.js` | **The one place to wire the demo form** (see below). |
| `assets/fonts/` | IBM Plex Sans Arabic 400/500/600/700 from the design system, with `OFL.txt`. |
| `assets/brand/` | The OpsOne logo and mark, generated from the original geometry in `docs/design-system/source/components/src/logo.js` (not redrawn). |
| `assets/ui/` | Real OpsOne screens taken from `docs/screenshots/` (showcase data), some cropped. |
| `assets/photos/` | Three open-licence photographs with `credits.json`. |

## Wiring the demo form (before launch)

`config.js` holds `window.OPSONE_LANDING = { demoEndpoint: '', demoEmail: '' }`.

- **`demoEndpoint`**: a URL that accepts `POST` with JSON `{ name, company, phone, city, trade, page, sentAt }`. A 2xx
  response shows «وصل طلبك…»; anything else, or a 15-second timeout, shows «تعذّر إرسال الطلب…». The endpoint must allow the
  page's origin (CORS) if it lives elsewhere.
- **`demoEmail`**: used only while `demoEndpoint` is empty. The form opens the visitor's mail app with the request written out
  (`mailto:`) and says the request arrives only once they send that mail.
- **Both empty (the current state)**: the form validates and then says «لم يُرسل طلبك: استقبال الطلبات من هذه الصفحة لم يُفعَّل بعد».
  It never shows a success it did not receive.

Validation runs in the browser only (name, business name, a Saudi mobile `05XXXXXXXX` / `+9665XXXXXXXX`, Arabic-Indic
digits accepted, trade required). The receiving endpoint must validate again on the server.

## How it was made

Run of the `design-taste-frontend` skill: direction **One motif** (`one-motif`), seed **142508230**, dealt 2026-11-02 with
`--sector saas --have product-ui --keep palette,type`. No slot was redrawn. Section blueprints: hero «Tabbed folder stack»,
«Many into one», «Line-up at one type size», «Square plate, spare text», «Close-ups on a board», «Clip-out coupon form»,
«The mark, built large». Signature move: «Index with preview».

References studied in a browser before building: ramp.com/en-gb (scroll-linked pieces gathering into one product, a step list
whose demo panel changes beside it, staged entrances) and clerk.com (live product components switched by tabs). What the page
takes from them: the squares in «ملف واحد» gather from scattered to ordered as the section scrolls; choosing a stage swaps the
real OpsOne screen beside the list; the people section switches real screens by tab; every section's parts enter in a stagger.

Brand decisions that override the skill's defaults: no negative tracking on Arabic display type (brand rule), line height 1.12
on the display size so Arabic marks are not clipped, one family (IBM Plex Sans Arabic, which carries its own Latin).

### Inventory (what only OpsOne has)

1. The name: Ops = operations, One = all in one place.
2. The mark: a teal "1" with a slanted flag at its top, standing among six ink tiles. The flag's slope
   (from 4,20 to 24,4 in the 64px mark) is the page's motif.
3. The trade's order path, in the product's own default stage names: القياس والمعاينة، التصميم والعرض،
   الاتفاق والدفعة الأولى، التصنيع، التركيب والتسليم.
4. Its printed artefacts: عرض سعر QUO-…, عقد CON-…, سند قبض RC-…, فاتورة ضريبية INV-…, رقم الطلب ORD-2026-00178.
5. Money rules: prices include 15% VAT; amounts in Latin digits with «ر.س» after.
6. One isolated instance per facility.
7. Customer portal: phone number and a 6-digit code; approve design, e-sign quote and contract, pay.
8. WhatsApp notifications to the customer at each stage.
9. Executors and their tools: فني القياس، المصمم، المبيعات، الورشة، فريق التركيب; the calendar flags clashes (تعارض).
10. Showroom, branches (الرياض، جدة، الدمام), online store for ready products.
11. Commission on the pre-VAT amount, in proportion to what is collected.
12. Arabic first, right to left; English available in the app.

### Argument

1. One Arabic system for businesses that make to order and install. → h1 and the real order screen in the front folder.
2. Built for this trade. → trade photographs and four product facts.
3. Today an order is scattered across paper, WhatsApp and notebooks; here it is one file. → the many-into-one diagram.
4. The path from معاينة to تسليم, who does what and what the customer gets. → the live order path.
5. Each person sees their part: team, customer, owner. → three real screens in tabs.
6. Everything else the business runs on is included. → close-up board and the full module list.
7. See it on your own orders. → the demo form.

### Idea and live thing

On this page, **the slanted flag on the "1" of the OpsOne mark** becomes **the one motif**: the hero's folder tabs, the cut
corner of every button, picture and plate, the bullets, the active-row marker, and the mark built large at the close.

Live thing: the order path. Choose a trade (مطابخ، ألمنيوم، خزائن وأبواب) and the example order, executors and amounts
(with VAT) are rewritten; choose a stage and the real OpsOne screen for it opens beside the list (inside the row on a phone or
tablet). Without JavaScript the kitchen example and the first screen show.

## Critique (last round)

```
ROUND 3
Seen in one second: «من المعاينة إلى التركيب» over a stack of coloured folders.
Idea (one sentence): the slanted flag on the "1" of the OpsOne mark becomes the one motif, from the folder tabs to the mark built large.
Live thing: the order path (trade switch + the real screen for each stage).
Layout words by section: hero=folder stack / trades=band split / one=diagram / path=index with preview / people=split plate / modules=mosaic / demo=coupon / footer=mark
Scores: design 7.5 · usability 8 · creativity 8 · content 8.5 → overall 7.9
Caps applied: none (inspect 0 FAIL at 1440/768/390; 3 photographs + 11 real product screens; live thing present).
Remaining problems (most visible first):
1. The first screen carries no picture: the real order screen starts just under the fold inside the front folder (inspector: hero media none).
2. No moving clip: the only honest clip found (a 71-second band saw) was too long and could not be trimmed here; the moving
   pictures are the slow drift on the installation photograph and the converging squares.
3. On desktop the WhatsApp and commission tiles leave their lower halves as flat colour.
Inspector warnings defended: «ragged-edges» measures left edges, and this page is right-to-left (its text shares the right
edges); «no-footage» as in 2.
```

Earlier rounds fixed: the demo heading wrapping to three lines at 768; the hero button below the first screen; amounts
splitting from «ر.س»; close-ups colliding with their captions on tablet and phone; the coupon's notches off the perforation;
a stage preview too small to read on tablet.

## Pictures and credits

Credited in the footer at body size.

- `assets/photos/carpentry-workshop-full-of-machines-and-wood.jpg`: “Carpentry workshop full of machines and wood” by Shixart1985,
  CC BY 2.0, via Wikimedia Commons. https://commons.wikimedia.org/wiki/File:Carpentry_workshop_full_of_machines_and_wood.jpg
- `assets/photos/aluminium-window-detail.jpg`: “Aluminium window detail” by ProfDEH, CC BY-SA 3.0, via Wikimedia Commons
  (converted from PNG to JPEG and resized, otherwise unaltered). https://commons.wikimedia.org/wiki/File:Aluminium_window_detail.png
- `assets/photos/kitchen-cabinet-installation.jpg`: “Kitchen cabinet installation” by Akpeski, CC BY-SA 4.0, via Wikimedia Commons.
  https://commons.wikimedia.org/wiki/File:Kitchen_cabinet_installation.jpg

These show the trade's world; none is presented as a customer or as OpsOne's own work. Product screens in `assets/ui/` are
OpsOne itself with showcase data. The interface close-ups on the board are built in HTML (`data-ui`) from the product's own
badges and values. The signature in the e-signature tile is drawn in SVG. No bundled stock photographs are used.

## Sample content to confirm before launch

- Example orders and splits: مطبخ 42,000 + 6,300 ضريبة = 48,300 ر.س (50/50 → 24,150)؛ ألمنيوم 54,000 + 8,100 = 62,100
  (→ 31,050)؛ خزائن 16,000 + 2,400 = 18,400 (→ 9,200). Item descriptions and executor names per trade.
- Commission example 2.5% on 42,000 = 1,050.00 ر.س, half collected = 525.00 (the basis follows the P6 default, which is
  still awaiting product-owner confirmation).
- WhatsApp message text, OTP digits, stock example (ألواح MDF 18 مم، 12، حد الطلب 20), store item (مقبض ستانلس 160 مم، 35.00 ر.س).
- «والإنجليزية متاحة لمن يحتاجها» and «فروع ومعرض ومتجر إلكتروني في النظام نفسه» as product facts.
- Names, branches and numbers visible in the screenshots come from the showcase dataset.
- ZATCA e-invoicing is not mentioned (not merged yet).
