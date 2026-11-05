# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Owners and managers of Saudi businesses that sell ready-made and made-to-order products from a showroom or an online
store, usually with their own factory or a field team that measures, installs and delivers. They evaluate OpsOne to
replace spreadsheets, WhatsApp threads and paper contracts across sales, production and delivery. The home page
describes the audience by how a business works; nine sector pages (kitchens, furniture, aluminium and glass, curtains,
fit-out, flooring, smart home, outdoor spaces, signage and exhibitions) speak to each sector by name, as the owner approved.

## Product Purpose

OpsOne manages the whole sale, from the first contact to the last signature: leads and appointments, quotes and
electronically signed contracts, payment plans and receipts, production and field visits, the customer portal and the
online store. Success for the marketing site is a demo request.

## Positioning

Sales management from the first contact to the last signature, in Arabic, for businesses whose sale continues after the
invoice (production, installation, delivery). Each business changes how the system works (stages, payments, terms,
tasks, roles) without a developer, with versioned releases that leave running orders on their version.

## Operating Context

- The product is a Laravel + React/Inertia app in `src/`; the marketing site is a static multi-page site in
  `marketing/site/` (ADR-0044, replacing ADR-0034), built by a dependency-free Node script (`build-site.mjs`).
- Demo requests go to an endpoint set in `marketing/site/config.js`; marketing tags load only after consent (two
  purposes: analytics and ads). Prices (ADR-0043) are not published until Q-74 is decided.

## Capabilities and Constraints

- Arabic, right-to-left first.
- Approved Arabic copy for «لمن OpsOne؟» and «من أول اتصال إلى آخر توقيع» is fixed; copy changes need the product
  owner's approval.
- Zakat-compliant e-invoicing is still marked «قيد الإطلاق» on the page; the claim needs a product-owner decision.

## Brand Commitments

- OpsOne identity from `docs/design-system/` (logo geometry, palette, IBM Plex Sans Arabic).
- Banned words: ثوري، سلس، الحل المتكامل. No "No-Code" wording.
- Factories are «مصنع/مصانع», never «ورشة/ورش»; nothing may imply the customer relies on external workshops.
- References named by the user: ramp.com/en-gb (motion) and clerk.com.

## Evidence on Hand

- Real product screens in `docs/screenshots/` and recorded clips in `marketing/site/assets/video/` (demo data).
- CC0 photos in `marketing/site/assets/photos/` and generated or licensed sector images in
  `marketing/site/solutions/*/img/` (see each `credits.json`).
- No customers, testimonials, logos or statistics exist. Do not fabricate any. Prices exist (ADR-0043) but are not
  published yet.

## Product Principles

1. Show the real product, with sample data that adds up, rather than abstract illustration.
2. Describe the business by how it sells and delivers; on sector pages, in that sector's own words.
3. Make only claims the product can back today.
4. One action: request a demo.

## Accessibility & Inclusion

Arabic RTL reading order, WCAG AA contrast, keyboard access and reduced-motion support.
