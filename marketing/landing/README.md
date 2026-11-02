# OpsOne landing page

A self-contained static page (HTML, CSS, JS; no build step; fonts and pictures local) that sells OpsOne to owners and
managers of Saudi **retailers and showrooms** that sell custom and ready products (kitchens, aluminium, furniture,
accessories). Arabic only, right to left. One action: request a demo.

**Positioning (product owner, 2026-11-02):** OpsOne is for the retailer, not the workshop. Most retailers outsource
manufacturing; OpsOne tracks the order around it (sending to the workshop, the expected date, receiving), the customer, the
payments, delivery and installation. A retailer that owns a workshop can add its own manufacturing stages and a responsible
person per stage without code; the page presents that as an option, not the core.
The folder choice is recorded in `docs/decisions/0034-marketing-landing-folder.md`.

## Open it locally

Serve the folder (opening `index.html` straight from disk also works, but Chrome then refuses the font preloads):

```bash
python3 -m http.server 8080 --directory marketing/landing
```

then visit `http://localhost:8080`.

## Files

| File | What it is |
| --- | --- |
| `index.html` | The page. |
| `styles.css` | Brand tokens (from `docs/design-system/source/tokens.json`), layout and motion. |
| `main.js` | The live order path, the people tabs and clips, the demo form, the scroll-linked diagram and the GSAP motion. |
| `config.js` | **The one place to wire the demo form** (see below). |
| `assets/fonts/` | IBM Plex Sans Arabic 400/500/600/700 from the design system, with `OFL.txt`. |
| `assets/brand/` | The OpsOne logo and mark, generated from the original geometry in `docs/design-system/source/components/src/logo.js` (not redrawn). |
| `assets/photos/` | Four open-licence photographs with `credits.json`. |
| `assets/video/` | Three muted loops recorded from the running showcase at 2× (2560×1600 / 780×1688, WebM/VP8) with JPEG posters. |
| `assets/vendor/` | GSAP 3.13.0 and ScrollTrigger 3.13.0, copied unchanged from cdnjs (GSAP's no-charge standard licence); `LUCIDE-LICENSE.txt` for the icons, which are inlined in `index.html` as an SVG sprite (Lucide 0.475, the app's icon set). |

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
«The mark, built large». Signature move: «Index with preview». The redesign round replaced the hero blueprint with the product window (reason: the
product owner asked for the product in the first screen at Ramp's scale) and pinned the index.

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
9. The people around an order: فني القياس، المصمم، المبيعات، الورشة المتعاقدة، فريق التركيب; the calendar flags clashes (تعارض).
10. Showroom, branches (الرياض، جدة، الدمام), online store for ready products.
11. Commission on the pre-VAT amount, in proportion to what is collected.
12. Arabic first, right to left; English available in the app.

### Argument

1. One Arabic system for businesses that make to order and install. → h1 and the real order screen in the front folder.
2. Built for showrooms that sell custom and ready products and usually outsource manufacturing. → showroom and in-home pictures, product facts, the own-workshop option.
3. Today an order is scattered across paper, WhatsApp and notebooks; here it is one file. → the many-into-one diagram.
4. The path from معاينة to تسليم, who does what and what the customer gets. → the live order path.
5. Each person sees their part: team, customer, owner. → three real screens in tabs.
6. Everything else the business runs on is included. → close-up board and the full module list.
7. See it on your own orders. → the demo form.

### Idea and live thing

On this page, **the slanted flag on the "1" of the OpsOne mark** becomes **the one motif**: the hero's folder tabs, the cut
corner of every button, picture and plate, the bullets, the active-row marker, and the mark built large at the close.

Live thing: the order path. Choose a trade (مطابخ، ألمنيوم، أثاث) and the example order, executors and amounts
(with VAT) are rewritten; choose a stage and the real OpsOne screen for it opens beside the list (inside the row on a phone or
tablet). Without JavaScript the kitchen example and the first screen show.

## Redesign round (2026-11-02, after the product owner's review)

The first version was judged not good enough. Changes, against ramp.com/en-gb and clerk.com studied again at desktop and phone width
(what makes them feel premium: the product itself as the hero at large scale, few words, generous rhythm, motion that shows the
product working rather than decorating it):

- **Hero = the product.** The folder stack is gone (it read as cluttered tab bars on the phone). The first screen now holds a large
  OpsOne window rebuilt in HTML from the real order screen (`data-ui`): sidebar, order ORD-2026-00178, its stages and tasks, order
  money with VAT, today's appointments. It covers about 52% of the first screen at 1440 and starts above the fold on a phone. The
  active file tab keeps the flag motif.
- **Choreography (GSAP + ScrollTrigger 3.13.0).** The window rises flat out of a tilt; panels and stages enter in sequence; the amounts
  count up; then manufacturing completes, installation starts, the status chip turns to «قيد التركيب» and a WhatsApp message to the
  customer appears. Each section's parts enter in a stagger; the close-up board counts its figures and fills its meters.
- **Pinned order path.** On wide screens the path holds still for about 2.2 screens while the stages pass and the real screen beside
  the list changes; clicking a stage scrolls to it. Phones and tablets keep the list with the screen inside the selected row.
- **Real product film.** Three loops recorded from the showcase at `localhost:8084` with Playwright (headless Chromium) and trimmed with
  Playwright's bundled ffmpeg: the order's stages and tasks (staff), the customer's journey and a design awaiting approval (portal,
  phone), and the owner dashboard switching period. They play muted, only while visible and only in the active tab; posters show
  first; under reduced motion they do not play. Credentials were read at run time from `UserFactory` and the stack's env and were
  never written to any file.
- **Fuller close-ups.** The WhatsApp tile is a three-message thread; the commissions tile shows the salesperson, base, collected share,
  a meter, the amount due now and at full collection.

## Section-by-section round (product owner review, 2026-11-02)

The product owner's direction overrides the skill's house rules where they conflict: icons and graphical devices are wanted. The
inspector runs with `--allow icon-cards` for the capability grid they asked for. Changes:

- Hero kept.
- **Benefits** («نظام واحد يدير معرضك كله»): eight short owner benefits with Lucide icons; no architecture talk; for all showrooms.
- **One strong picture** (a customer trying faucets in a showroom, 2400px) beside four ordered steps with icons, replacing the moving squares.
- **Stage control** («تحكّم في مراحل العمل»): a workflow editor rebuilt in HTML at native resolution: stages, a responsible person per
  stage, target durations, gates (no sending to the workshop before the first payment, no closing before the last receipt), an overdue
  alert. Four features take turns while the section is pinned on wide screens; clicking one jumps to it.
- **Roles and permissions per branch**: manager, sales and technician tabs (owner dashboard clip, order clip, an HTML technician
  phone with photos), a refund approval waiting for the branch manager, and a permission matrix scoped to a branch.
- **Tools** («من عرض السعر إلى الفاتورة»): WhatsApp in a phone, a quote page with an electronic signature, a simplified tax invoice
  with a QR mark (labelled «قيد الإطلاق»), a store product card, the portal clip in a phone, payments.
- **Capability grid** with icons and one-line benefits replaces the old module list.
- **Footer**: final call to action, normal-size logo, product / audience / contact columns, small credits.
- Clips re-recorded at 2× (CSS zoom on a double-size viewport) so they are sharp; the old low-resolution screenshots were removed.

## Critique (last round)

```
ROUND 7
Seen in one second: «من المعاينة إلى التركيب» over a large, working OpsOne order window.
Idea: the slanted flag of the "1" in the mark is the motif (file tab, cut corners of pictures, clips and buttons), and the page shows
  the product doing the showroom's work.
Live things: the stage-control features (pinned, switch on scroll or click), the roles tabs with real clips, the order choreography in the hero.
Layout words by section: hero=product window / benefits=icon grid / journey=picture split / control=pinned editor / roles=tabs+film+matrix /
  tools=mosaic of mock-ups / capabilities=icon grid / demo=coupon / footer=columns
Scores: design 8.5 · usability 8.5 · creativity 8 · content 8.5 → overall 8.4
Caps applied: none (inspect 0 FAIL at 1440/768/390 with --allow icon-cards per the product owner; lint 0).
Remaining problems:
1. The pictures are open-licence pictures from outside Saudi Arabia; a pilot customer's showroom and installations would be stronger.
2. Two icon grids (benefits, capabilities) on one page; they differ in treatment but a stricter designer would keep one.
3. The QR on the invoice mock-up is a drawn mark, not a scannable ZATCA code, and e-invoicing is still «قيد الإطلاق».
4. Clips are WebM/VP8 only; older Safari shows the poster.
Inspector warning defended: «off-centre» measures left edges; on this right-to-left page those are the ends of right-aligned lines.
```

Earlier rounds (1–3, before the redesign) fixed: the demo heading wrapping to three lines at 768; the hero button below the first screen; amounts
splitting from «ر.س»; close-ups colliding with their captions on tablet and phone; the coupon's notches off the perforation;
a stage preview too small to read on tablet.

## Pictures and credits

Credited in the footer.

- `assets/photos/showroom-customer.jpg`: “Man tests kitchen faucet at home improvement store in modern showroom setting” by Shixart1985,
  CC BY 2.0, via Wikimedia Commons (resized). https://commons.wikimedia.org/wiki/File:Man_tests_kitchen_faucet_at_home_improvement_store_in_modern_showroom_setting.jpg
- `assets/photos/home-kitchen-windows.jpg`: “Modern kitchen and dining area with stylish furnishings and natural light in a contemporary
  home setting” by Shixart1985, CC BY 2.0, via Wikimedia Commons (resized).
  https://commons.wikimedia.org/wiki/File:Modern_kitchen_and_dining_area_with_stylish_furnishings_and_natural_light_in_a_contemporary_home_setting.jpg
- `assets/photos/kitchen-cabinet-installation.jpg`: “Kitchen cabinet installation” by Akpeski, CC BY-SA 4.0, via Wikimedia Commons.
  https://commons.wikimedia.org/wiki/File:Kitchen_cabinet_installation.jpg
- `assets/photos/store-faucet.jpg`: “Kitchen Faucet 1” by Stilfehler, CC0, via Wikimedia Commons (resized).
  https://commons.wikimedia.org/wiki/File:Kitchen_Faucet_1.jpg
- Icons: Lucide (ISC).

Product clips in `assets/video/` are OpsOne itself, recorded from the showcase dataset (no credit needed).

These photographs show the trade's world; none is presented as a customer or as OpsOne's own work. Product screens in `assets/ui/` are
OpsOne itself with showcase data. The interface close-ups on the board are built in HTML (`data-ui`) from the product's own
badges and values. The signature in the e-signature tile is drawn in SVG. No bundled stock photographs are used.

## Sample content to confirm before launch

- Stage editor: stage names, responsible roles, durations (يومان، 3 أيام، يوم، 21 يومًا), the two gates and the overdue alert.
- Roles: the refund request 2,700.00 ر.س and the permission matrix (which role may do what) must match the real role setup.
- Tools: the quote QUO-2026-00070 (from the showcase PDF), the invoice INV-2026-01187 amounts, the faucet price 1,150.00 ر.س.
- Footer: contact details (email, phone, WhatsApp, address) are missing and needed from the product owner.
- Hero window tasks for the outsourced stage: «إرسال أمر التصنيع للورشة» (المشتريات), «تأكيد موعد الجاهزية 6 نوفمبر» (الورشة المتعاقدة),
  «استلام البنود من الورشة» (المستودع); executors per trade: ورشة المطابخ المتعاقدة، مصنع الألمنيوم المتعاقد، ورشة الأثاث المتعاقدة.
- Example orders and splits: مطبخ 42,000 + 6,300 ضريبة = 48,300 ر.س (50/50 → 24,150)؛ ألمنيوم 54,000 + 8,100 = 62,100
  (→ 31,050)؛ أثاث (غرفة نوم بخزانة أبواب منزلقة) 16,000 + 2,400 = 18,400 (→ 9,200). Item descriptions and executor names per trade.
- Commission example 2.5% on 42,000 = 1,050.00 ر.س, half collected = 525.00 (the basis follows the P6 default, which is
  still awaiting product-owner confirmation).
- WhatsApp message text, OTP digits, stock example (ألواح MDF 18 مم، 12، حد الطلب 20), store item (مقبض ستانلس 160 مم، 35.00 ر.س).
- «والإنجليزية متاحة لمن يحتاجها» and «فروع ومعرض ومتجر إلكتروني في النظام نفسه» as product facts.
- Hero window content: ORD-2026-00178 for سلطان أحمد الأحمدي, stage dates (9، 14، 23 سبتمبر، 6 و8 نوفمبر), the three tasks,
  today's appointments (حسن، زياد، عمر) and the WhatsApp message «طلبك ORD-2026-00178 جاهز للتركيب…».
- WhatsApp thread texts and the commission example for خالد بن سعود الشمري.
- Names, branches and numbers visible in the screenshots and clips come from the showcase dataset.
- ZATCA e-invoicing is not mentioned (not merged yet).

## What would raise the page further (needs the product owner)

1. **Real footage and photographs from a pilot customer** (their showroom, a customer choosing finishes, finished kitchens and installed
   aluminium windows and doors in homes, an installation day), 10–20 s
   horizontal clips and a few stills shot on a phone at 4K, with written permission to publish. They replace the Commons photographs
   and give the band «لمن صُمّم» the customer's own world.
2. **A full ffmpeg** on the build machine (`brew install ffmpeg`) to add H.264 MP4 versions of the clips for older Safari/iOS and
   smaller files; the recorder in this round could only write WebM/VP8.
3. **Product photographs for the showcase store** so the store and checkout can be filmed like the other flows.
4. **Real customer names, logos or quotes** once a customer agrees; the page has none by design until then.
