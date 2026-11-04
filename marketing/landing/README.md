# OpsOne landing page

A self-contained static page (HTML, CSS, JS; no build step; fonts and pictures local) that sells OpsOne to owners and
managers of Saudi businesses that sell ready and custom products from a showroom or an online store. The audience is
described by **how a business works, never by sector** (product owner, 2026-11-02): no kitchens, aluminium, furniture or
accessories anywhere in copy, meta, alt text or pictures. Arabic only, right to left. One action: request a demo.

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
| `config.js` | **The one place to fill**: the demo form target and the marketing tag IDs. |
| `tags.js` | Consent-aware loader for GA4, GTM, Meta, TikTok, Snap and LinkedIn tags. |
| `robots.txt`, `sitemap.xml`, `site.webmanifest` | Crawling and install metadata (domain placeholder inside). |
| `HOSTING.md` | Suggested compression, cache and security headers. |
| `REDESIGN-AUDIT.md` | The critique, technical audit and detector scan that the 2026-11-04 redesign answered. |
| `assets/fonts/` | IBM Plex Sans Arabic 400/500/600/700 from the design system, with `OFL.txt`. |
| `assets/brand/` | The OpsOne logo and mark, generated from the original geometry in `docs/design-system/source/components/src/logo.js` (not redrawn). |
| `assets/photos/` | Four CC0 photographs (no credit required) with `credits.json` as the internal provenance record. |
| `assets/video/` | Three muted loops recorded from the running showcase at 2× (2560×1600 / 780×1688, WebM/VP8) with WebP posters. |
| `assets/vendor/` | GSAP 3.13.0 and ScrollTrigger 3.13.0, copied unchanged from cdnjs (GSAP's no-charge standard licence); `LUCIDE-LICENSE.txt` for the icons, which are inlined in `index.html` as an SVG sprite (Lucide 0.475, the app's icon set). |

## Before launch: what to fill

| What | Where | Notes |
| --- | --- | --- |
| Domain | `index.html` (head), `robots.txt`, `sitemap.xml` | Every absolute URL uses the placeholder `https://www.opsone.example`. Replace it in those three files, e.g. `grep -rl "https://www.opsone.example" marketing/landing \| xargs sed -i.bak "s#https://www.opsone.example#https://YOUR-DOMAIN#g"` then delete the `.bak` files. |
| Demo form endpoint | `config.js` → `demoEndpoint` (or `demoEmail`) | See below. |
| Marketing tag IDs | `config.js` → `tags` | See «Marketing tags». |
| Developer link | `config.js` → `developerUrl`, and `"url"` on the author/creator Organization in the JSON-LD | Footer credit «تطوير Available Solutions» is plain text until an `https://` URL is set; then it opens in a new tab (`rel="noopener"`). |
| Contact details | footer «تواصل» column in `index.html` | Email, phone, WhatsApp, address are not on the page yet. |
| Hosting headers | the host | `HOSTING.md`. |

## Marketing tags (consent-aware)

`tags.js` loads Google Analytics 4, Google Tag Manager, Meta Pixel, TikTok Pixel, Snap Pixel and LinkedIn Insight. Fill
only the IDs you use in `config.js`:

```js
tags: {
  ga4: 'G-XXXXXXXXXX',          // GA4 measurement ID
  gtm: 'GTM-XXXXXXX',           // GTM container (use GA4 inside GTM or the ga4 field, not both)
  googleAdsLead: 'AW-123/abc',  // optional Google Ads conversion send_to for the demo request
  metaPixel: '1234567890',
  tiktokPixel: 'CXXXXXXXXXXXXXXX',
  snapPixel: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
  linkedin: '1234567',          // Insight partner ID
  linkedinLeadConversion: '7654321',
}
```

- Nothing loads while every ID is empty, and no banner shows.
- With an ID set, a small Arabic banner offers «موافقة» or «رفض». Until the visitor accepts, no tag script is requested and
  Google Consent Mode v2 stays `denied` (ad_storage, analytics_storage, ad_user_data, ad_personalization). The choice is kept in
  the browser; «إعدادات الخصوصية» in the footer reopens it.
- The demo request fires once, when the request is really sent (endpoint 2xx, or the mail app opened): GA4 `generate_lead`,
  the Google Ads conversion if set, GTM `demo_request`, Meta `Lead`, TikTok `SubmitForm`, Snap `SIGN_UP`, LinkedIn conversion if set.
- `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid` and `fbclid` are read from the landing URL, kept
  for the visit, and sent with the form as hidden fields.

Verified in Chrome with test IDs and the network watched: no external request before consent, Google and Meta requested only after
«موافقة», consent `default` then `update`, `generate_lead` and `Lead` on a successful submit, UTM values and gclid in the posted JSON.

## SEO and sharing

- `lang="ar" dir="rtl"`, one `h1`, section headings in order, descriptive `alt` on every content image (images inside the
  `aria-hidden` mock-ups are decorative).
- Title and description in Arabic, canonical and `hreflang="ar"`, `robots` meta, `robots.txt`, `sitemap.xml`.
- Open Graph (`ar_SA`) and Twitter `summary_large_image` with `assets/og-image.jpg` (1200×630, a capture of the page's own hero).
- JSON-LD `@graph`: Organization (name, logo), WebSite, SoftwareApplication (BusinessApplication, no price or ratings).
- Icons: SVG favicon, 32px PNG, 180px apple-touch icon, 192/512 for `site.webmanifest`, `theme-color`.

## Performance (Lighthouse 12.8.2, headless Chrome for Testing)

Measured on a local server that compresses text with Brotli and sends the cache headers in `HOSTING.md`.

| | Performance | Accessibility | Best practices | SEO | LCP | CLS |
| --- | --- | --- | --- | --- | --- | --- |
| Mobile, before | 88 | 88 | 100 | 100 | 4.0 s | 0 |
| Mobile, after | 97 | 100 | 100 | 100 | 2.6 s | 0.001 |
| Desktop, before | 100 | 88 | 100 | 100 | 0.8 s | 0 |
| Desktop, after | 100 | 100 | 100 | 100 | 0.6 s | 0.002 |

(On Python's plain server, without compression, the before score was mobile 72 / desktop 98.) Page weight on mobile went from
1,446 KB to 504 KB. What changed: photographs and posters re-encoded to WebP at their display sizes (85–95% smaller, with
`srcset` on the large picture); the hero window's entrance animates transform only, so its text paints on the first frame;
the tab list markup fixed for screen readers. Fonts stay the original IBM Plex files (`font-display: swap`, the two weights
above the fold preloaded): the licence reserves the name «Plex» for modified fonts and the design system uses them unmodified,
so they are not subset. GSAP is deferred; reduced motion keeps every element visible and stops autoplay.

## Wiring the demo form (before launch)

`config.js` holds `window.OPSONE_LANDING = { demoEndpoint: '', demoEmail: '' }`.

- **`demoEndpoint`**: a URL that accepts `POST` with JSON `{ name, company, phone, city, trade, utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid, page, sentAt }`. A 2xx
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

## Audience round (product owner, 2026-11-02)

- All sector naming removed from copy, title, description, Open Graph, JSON-LD, alt text and the share image (re-captured from the
  new hero). «ورشة/ورش» became «مصنع/مصانع»; no line implies the business relies on outside workshops. Owning a factory is one of
  the traits, not the core.
- Positioning is sales management: title «إدارة البيع من أول اتصال إلى آخر توقيع», description, OG and JSON-LD follow it.
- **«لمن OpsOne؟»** (`#fit`, first after the hero) and **«من أول اتصال إلى آخر توقيع»** (`#sales`) carry the product owner's
  approved Arabic copy word for word. `#sales` replaced the old «من المعرض إلى بيت العميل» steps and the pinned stage-control
  section: eight numbered cards with icons, then item 9 as a wide dark panel (`#path`) beside a settings window built from the
  product's stage editor (side menu of settings areas, stages with owners, durations and gates, a draft version awaiting approval).
- The demo form's «النشاط» list became «ماذا تبيع؟» (جاهز / مخصص أو حسب الطلب / الاثنان); the field is still sent as `trade`.
- Visible photo credits removed; the three photographs that required attribution and the faucet were replaced with CC0 pictures.
- Lighthouse 12.8.2 (Brotli server): mobile 97 / 100 / 100 / 100 (LCP 2.6 s, 509 KB); desktop 100 / 100 / 100 / 100.
- Inspector at 1440/768/375: lint 0. The phone «blank-gap» FAIL is the inspector's own limit (it walks at most 12 screens to fire
  scroll reveals; the phone page is longer, so later sections are still hidden when it measures); a full scroll shows them rendered.
  WARNs «off-centre» and «ragged-edges» (right-to-left line ends) and «lopsided» on `#sales` (measured before its picture reveals)
  are defended.

## Design redesign round (2026-11-04, impeccable and emil-design-eng skills)

The pre-redesign critique, technical audit and detector scan are in `REDESIGN-AUDIT.md`; product truth for future design
work is in the repository root `PRODUCT.md`. Copy, sections, SEO, consent tags and the CC0 photographs are unchanged except
where noted.

- This round extends the established OpsOne brand world (no new visual direction was rolled).
- **The sales journey is the page's centre.** `#sales` items 1–8 became a journey pinned while scrolling (desktop): the text
  column scrolls and a sticky stage beside it switches to the screen that does each step. On phones, and without JavaScript, each
  step stacks its text and its screen. The eight screens are built from the app's own components and tokens
  (`src/resources/js/components/ui/badge.tsx`, `button.tsx`, `card.tsx`, `flow/order-stages.tsx`, the portal sign panel):
  sales opportunities by status and channel, appointment requests with a team clash, the quote with its terms, design versions
  approved from the client's phone, the e-signature panel with the verification code, the payment plan, the client file with
  access, and the field visit. They all follow one order, ORD-2026-00178, and its numbers add up (see «Sample content»).
- **Item 9** (`#path`) is a full-width dark band. Its settings window shows a draft stage («التشطيب») and, as it scrolls into
  view, «اعتماد» publishes version 4 while running orders stay on version 3.
- **Hero window** rebuilt from `app-shell.tsx` and `navigation.ts`: sidebar with the real Lucide icons and labels, top bar with
  page title, search, language, notifications and user, square tabs, stage rows with numbered dots. The WhatsApp notice now
  floats beside the window (below it on tablets and phones) instead of covering the money panel.
- **Header and logo:** the bar is sticky, so «اطلب عرضًا تجريبيًا» is always one tap away; the logo is 148×36 (123×30 on phones).
  «مراحل العمل» now leads to the journey (`#sales`), not to the settings band.
- **Hierarchy:** the icon-card grids in `#fit` and `#benefits` became ruled lists led by their headings, with no icons
  (`#capabilities` keeps small icons as an index); the icon beside the step numbers 1–9 was removed; one radius system (the logo's
  corner cut on marketing containers, 12 px on product screens, 6 px badges); one elevation token (`--lift`: hairline ring plus
  an offset soft drop); titles always in ink.
- **Tools and roles:** the money tile, which repeated the hero, now shows the receipt voucher RCV-2026-00412. WhatsApp is
  drawn as the client's phone, and captions are ink on light tiles. The portal tile is no longer a teal fill. The recordings
  are cropped to the page area (the owner and order clips without the sidebar, the portal clip's top half), so their
  figures can be read on desktop. On phones they are still small; re-recording at phone size is a follow-up.
- **Footer:** the repeated «جاهز ترى OpsOne على طلباتك؟» row was removed (the form sits right above); links are 44 px tall;
  the mark closes the page as a large signature.
- **Form:** an ink band with the corner cut instead of the teal perforated coupon; teal only on the submit button; fields
  with a visible edge, 16 px text (no zoom on iOS), a chevron on the select.
- **Motion** (find-animation-opportunities, animate, review-animations, animation-performance): motion only where it explains a
  state change. These are a rail beside the step being read (its title turns ink), the hero's order advancing (from 2.4 s, not 3.4 s), the stage crossfade, the design approval stamp,
  the signature drawing, and the version 4 release. The blanket rise on every list item and the photo parallax were removed.
  Transform and opacity only; reduced motion gets a 200 ms crossfade on the steps and no GSAP. The hero's 3D tilt runs on
  screens 1024 px and wider only, because on phones it held back the first paint (Lighthouse LCP 4.2 s → 2.9 s).
- **Mobile-native:** no tap highlight, `touch-action: manipulation`, no text selection on buttons and tabs, safe-area padding
  on the sticky bar and the consent banner (`viewport-fit=cover`).
- **break-ui:** no overflow at 320, 375, 768 or 1440, nor at 200% text (the settings window lost its side menu below 1024 px to
  make that hold); a long name, a long company and Arabic-digit phone input in the form; a long email in the status line wraps.

## Hero copy and developer credit (2026-11-04, approved by the user)

- Headline «عملياتك كلها، في مكان واحد», always on two lines (checked at 320, 375, 768 and 1440); supporting line «من البيع في
  المعرض وعرض السعر والدفعات، إلى التصنيع والتسليم والتركيب والمتجر الإلكتروني.»
- «عربي» no longer describes the system anywhere: title «OpsOne | عملياتك كلها، في مكان واحد»; description, Open Graph,
  Twitter and JSON-LD start «OpsOne يجمع عملياتك كلها في مكان واحد، من أول اتصال إلى آخر توقيع». The benefit «عربي وإنجليزي» stays:
  it names the interface languages, not the system.
- Footer credit «تطوير Available Solutions»; the SoftwareApplication in the JSON-LD names Available Solutions as `author` and
  `creator` (name only until the URL is confirmed). Share image re-captured.

## Demo names (user correction, 2026-11-04)

Every person on the page has a generic two-part name (first name and father's name), with no family, tribe or nickname:
محمد صالح (the journey's client), عبدالله علي (the signed-in owner), سارة أحمد (the signed quote), ناصر خالد، هيفاء سعد، حمد سعود،
منيرة علي (sales opportunities), ريم يوسف، فهد ناصر (appointment requests), نورة محمد، خالد إبراهيم (staff following up), حسن علي،
زياد سالم، عمر يوسف (field team). The recorded clips still show full showcase names and must be re-recorded with the other pending
clips: the order clip and poster («عبدالله بن سعد القحطاني», «ريم بنت محمد السبيعي», «ياسر بن فهد القحطاني») and the owner clip
and poster («عبدالله بن سعد القحطاني»). The portal poster shows no person's name.

## Critique (round before the redesign)

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

Nothing is credited on the page: every photograph is CC0 (public-domain dedication, `creditRequired: false`), so the earlier
CC BY / CC BY-SA photographs were removed rather than left uncredited. Provenance stays in `assets/photos/credits.json`.

- `assets/photos/consultation-1600.webp` (and `-800`): “Rawpixel.com 2017-06-02 (Unsplash)” by rawpixel.com, CC0, via Wikimedia
  Commons (resized). https://commons.wikimedia.org/wiki/File:Rawpixel.com_2017-06-02_(Unsplash).jpg
- `assets/photos/field-tools-600.webp`: “Taduuda 2016-03-24 (Unsplash Geh-r9A4RBg)” by Taduuda, CC0, via Wikimedia Commons (resized).
  https://commons.wikimedia.org/wiki/File:Taduuda_2016-03-24_(Unsplash_Geh-r9A4RBg).jpg
- `assets/photos/finished-space-600.webp`: “Minimal creative interior (Unsplash)” by Breather, CC0, via Wikimedia Commons (resized).
  https://commons.wikimedia.org/wiki/File:Minimal_creative_interior_(Unsplash).jpg
- `assets/photos/store-pendant-light-600.webp`: “Light bulbs in a coffee shop (Unsplash)” by Tomas Jasovsky, CC0, via Wikimedia
  Commons (resized). https://commons.wikimedia.org/wiki/File:Light_bulbs_in_a_coffee_shop_(Unsplash).jpg
- Icons: Lucide 0.475 (ISC), notice in `assets/vendor/LUCIDE-LICENSE.txt` and a comment above the sprite in `index.html`.

CC0 waives copyright only. The consultation photograph shows two recognisable people; it is a generic stock scene, not presented
as a customer, but a pilot customer's own photographs remain the better choice (see below).

Product clips in `assets/video/` are OpsOne itself, recorded from the showcase dataset (no credit needed). **The order and portal
clips still show the showcase's sample orders, which name kitchens** (order line «مطبخ مع بار شبه جزيرة», portal summary
«مطبخ لشقة للإيجار»). Re-record them once the showcase has sector-neutral sample orders.

The interface close-ups are built in HTML (`data-ui`) from the product's own badges and values. The signature in the
e-signature tile is drawn in SVG.

## Sample content to confirm before launch

- Settings window (item 9): stage names, responsible roles, durations (يومان، 3 أيام، 45 يومًا), the two gates, «مسودة الإصدار 4».
- Roles: the refund request 2,700.00 ر.س and the permission matrix (which role may do what) must match the real role setup.
- Tools: the quote QUO-2026-00070 (from the showcase PDF), the invoice INV-2026-01187 amounts, the store item «مصباح معلّق بقفص معدني» at 385.00 ر.س.
- Footer: contact details (email, phone, WhatsApp, address) are missing and needed from the product owner.
- Hero window tasks for the manufacturing stage: «إصدار أمر التصنيع» (الإنتاج), «تأكيد موعد الجاهزية 6 نوفمبر» (المصنع),
  «استلام البنود من المصنع» (المستودع); the order is «طلب مخصص حسب المقاسات، 6 بنود», 48,300 ر.س split 50/50 → 24,150.
- Tools tile quote (signed PDF): «تصميم وتنفيذ حسب المقاسات» 33,540.00، «منتجات جاهزة من المعرض» 5,070.00، «تركيب وتوصيل وتجهيز الموقع» 1,524.00.
- The journey's order ORD-2026-00178, one set of numbers across the hero and the eight steps:
  - Quote QUO-2026-00071 (15 Sep, valid to 29 Sep). Lines: 36,500.00 + 4 × 1,150.00 + 2,900.00 = 44,000.00. Discount 2,000.00
    («اعتمده المدير»), giving 42,000.00. VAT 15% is 6,300.00, for a total of 48,300.00.
  - Contract CON-2026-00031, signed 23 Sep at 11:08. First payment 24,150.00 (receipt RCV-2026-00412, bank transfer);
    the last 24,150.00 is due at installation on 8 Nov, with a reminder two days before.
  - Design versions 1–3 (10, 12, 14 Sep). The client file has measure, design, contract and receipt PDFs.
  - Field visit: arrived 08:04, client signed at 11:52.
  - Terms: valid 14 days; 50% at signing, the rest at installation; manufacturing starts after the first payment and takes
    45 days (23 Sep to 6 Nov), as in the settings window. The hero's stage names are the version 3 names in the settings window,
    and today's 08:00 appointment is this order's installation.
- Sales opportunities: 18 this month (5 new, 7 in follow-up, 4 converted, 2 closed), INT-0294…0299.
- Appointment requests: 3 and 4 Nov, including a clash between ORD-2026-00127 and ORD-2026-00089 for the same team.
- Settings release: draft stage «التشطيب» (المصنع، 3 أيام), version 4 published while running orders stay on version 3.
- The approved copy states e-invoices compliant with هيئة الزكاة, while the invoice tile still says «قيد الإطلاق»; align both
  before launch.
- Commission example 2.5% on 42,000 = 1,050.00 ر.س, half collected = 525.00 (the basis follows the P6 default, which is
  still awaiting product-owner confirmation).
- WhatsApp message text, OTP digits, stock example (ألواح MDF 18 مم، 12، حد الطلب 20), store item (مقبض ستانلس 160 مم، 35.00 ر.س).
- «والإنجليزية متاحة لمن يحتاجها» and «فروع ومعرض ومتجر إلكتروني في النظام نفسه» as product facts.
- Hero window content: ORD-2026-00178 for محمد صالح, stage dates (9، 14، 23 سبتمبر، 6 و8 نوفمبر), the three tasks,
  today's appointments (زياد سالم، حسن علي، عمر يوسف) and the WhatsApp message «طلبك ORD-2026-00178 جاهز للتركيب…».
- WhatsApp thread texts.
- Names, branches and numbers visible in the screenshots and clips come from the showcase dataset.
- ZATCA e-invoicing is not mentioned (not merged yet).

## What would raise the page further (needs the product owner)

1. **Real footage and photographs from a pilot customer** (their showroom, a customer choosing, a delivery or installation day,
   a finished space), 10–20 s
   horizontal clips and a few stills shot on a phone at 4K, with written permission to publish. They replace the Commons photographs
   and give the band «لمن صُمّم» the customer's own world.
2. **A full ffmpeg** on the build machine (`brew install ffmpeg`) to add H.264 MP4 versions of the clips for older Safari/iOS and
   smaller files; the recorder in this round could only write WebM/VP8.
3. **Product photographs for the showcase store** so the store and checkout can be filmed like the other flows.
4. **Real customer names, logos or quotes** once a customer agrees; the page has none by design until then.
