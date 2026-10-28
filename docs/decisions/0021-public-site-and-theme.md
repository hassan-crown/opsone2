# ADR-0021: Public site, page editor, Alpha theme and custom domain (P8 part A)

Status: accepted (P8a). Domain 07 — WEB-01..04, THM-01..07, SUB-19 (facility side). Decisions Q-46 (a), Q-49 (a), Q-50 (a), Q-51 (a).

## Context

Each tenant deployment serves its own public website next to the staff panel. It must be bilingual (ar default RTL, en LTR), fast and
SEO-friendly, editable by the facility without free code, and brandable with one colour that can never make text unreadable. The store
and the customer workspace (other P8 sessions) will live on the same surface and reuse the theme.

## Decisions

### Surface and rendering
- The public site is **server-rendered Blade** (no Inertia, no JS bundle) in `resources/views/site/`, on its own `<body data-surface="site">`
  with its own CSS entry `resources/css/site.css` (design-system tokens + base; it imports `app.css`, Tailwind only emits what the views use).
  The staff panel stays Inertia with `data-surface="panel"`. A tiny inline script handles light/dark (cookie `appearance`).
- One URL per language: `/{ar|en}` (home), `/{ar|en}/{slug}`; `/` serves the home page in the facility default language (canonical points at
  `/{locale}`, so the old `route('home')` stays 200 but is now the site, not the starter `welcome` page; the panel-surface tests moved to `/login`).
  The language is taken from the URL by `SiteLocale`, never from a cookie. A language without a published copy redirects to the other language
  (or 404 when none); an archived page redirects to the home page; drafts and system pages are 404.
- SEO per language: `<title>`, description, canonical, `hreflang` alternates (only published languages) + `x-default`, Open Graph, `sitemap.xml`
  (every published language of every live page with xhtml alternates) and a dynamic `robots.txt` route (with the `Sitemap:` line for the canonical origin).
  **Server note:** the shipped `nginx.conf` has `location = /robots.txt` serving only the static file, so the dynamic route is unreachable there; `public/robots.txt`
  stays as a static fallback (disallow list only, no host). To expose the dynamic one the server admin must change that block to `try_files $uri /index.php?$query_string;`
  (infrastructure file — not edited in P8a).
  The canonical origin is the **verified custom domain** when one exists, otherwise `APP_URL`.
- Q-49 a: the footer shows a small «بواسطة OpsOne / Powered by OpsOne»; without a logo the facility name is shown as text.

### Pages and versions (M-17)
- Tables `site_pages` (policy `content|editable|system`, `archived_at`), `site_page_versions` (one row per language and number; immutable once
  `published_at` is set — enforced in the model) and `site_page_locales` (the two pointers: published and draft version per language).
  **State is derived** (draft / published / published with draft / archived), exactly as the state-machine doc says.
- The single write path is `App\Actions\Site\SitePageWriter` (create, saveDraft, publish, unpublish, discardDraft, archive, restore): authorization
  P-55 (edit) / P-56 (publish, unpublish, archive, restore) through the central Gate, policy and state checks inside, one transaction with the audit
  event and the `state_transitions` row (M-17 T00..T07). So the rules hold for the API as for the screen (WEB-04).
- Policies: `content` pages can be added/archived/unpublished; `editable` (home) can only be edited and republished; `system` (store, account — reserved
  for the store and customer-workspace sessions) are never edited or listed publicly. Reserved slugs cannot be taken. A published page keeps its path.
- Publishing is **per language**: the complete language publishes, an incomplete one is reported (and stays as it was — the last published copy remains live,
  an unpublished language is never exposed). Nothing is hard-deleted; restore returns a draft and never republishes by itself.
- Q-51 a: sections are a fixed catalogue (`hero, text, services, gallery, testimonials, faq, contact, ready_goods`) validated server-side
  (`SectionCatalogue`): known keys only, plain text only (HTML tags rejected, output escaped), links are internal (`#contact`, `home` or a live slug), images
  must be attachments of the same page. `ready_goods` is a **placeholder** the store session fills; it renders only when the license allows `ready_goods`.
- Media are private attachments (`AttachmentService`, owner = `SitePage` / `SiteTheme`). Q-50 a: **PNG/WebP only**, content-verified, ≤ 5 MB, 16–4000 px,
  favicon square (`SiteImage` rule). They are served by `site.media` **only when they belong to the live site** (a published version of a live page or the
  published theme); editors preview drafts through `site.admin.media` (P-55 / P-100). Anything else is 404.

### Lead form (WEB-02, P-72)
- `POST /{locale}/contact` creates exactly one `Interest` (channel `website`, status `new`, branch = first active branch, no account, no price) via
  `SubmitLead`, audited and with an M-03-T00 transition (source `website`). The form carries a `submission` UUID: a retry returns the same interest
  (`site_lead_submissions`, unique key) so the visitor gets one reference. Spam: hidden honeypot, an encrypted form-age token (< 3 s or forged → silently
  dropped with the same success answer), and `throttle:site-lead` (5/hour and 20/day per IP, configurable in `config/site.php`). No third-party captcha.

### Theme (THM-01..07, M-35)
- `BrandPalette` (PHP, no library; sRGB ⇄ OKLCH + WCAG) derives the brand roles for light, dark and contrast from **one colour**, keeping hue and capped chroma:
  `brand`, hover, active, subtle, subtle-text, on-brand, focus, halo. The guard checks six pairs per mode (text 4.5:1, 7:1 in contrast, focus 3:1). A colour that
  fails is **auto-adjusted** (lightness shift ≤ 0.30, message «adjusted») or **rejected** (shift too large or no readable palette, bilingual message); the
  derived palette is re-validated before it is ever applied. Neutrals, status colours, highlight, spacing and radii never change. Only validated hex values are
  injected into one `<style>` block scoped to `[data-surface='site']` (never user text).
- `site_theme` is one row: `published` (null = default Alpha) and one private `draft` (M-35). Saving needs P-100; **apply / restore need P-101** (owner, manager);
  discard drops only the draft; restore puts default Alpha into the draft and never touches pages, content or issued documents. Fonts are limited to the two
  self-hosted IBM Plex stacks; default appearance system/light/dark; logo, dark logo and favicon as theme attachments.
- Hand-over for the customer-workspace/store sessions: render `<style>{!! ThemeSettings::css(ThemeSettings::published()) !!}</style>` on their surface (same
  `data-surface="site"` scope) and use `SiteContext` (`facilityName`, `theme`, `url`) — do not re-derive colours.

### Custom domain (SUB-19, Q-46 a) — documentation only for the server
- `site_domains` holds at most one custom host (normalized, no scheme/path/port/IP, reserved zones refused via `config('site.reserved_domains')`), a random
  verification token and its status (`pending → verified | failed`). The owner/manager (P-101) publishes `TXT _opsone-verify.<host> = opsone-site-verification=<token>` and
  presses *Check proof*; `DomainManager` resolves it through the swappable `DnsTxtResolver` (tests use a fake; a failed lookup never downgrades a verified domain).
- **What the application does not do**: routing the host to this deployment and its TLS certificate. **Server admin checklist** (no infra file was edited here):
  1. after the status is *verified*, add the host to the tenant's Traefik router rule (`Host(\`<custom>\`)` next to the OpsOne subdomain) in the environment's override;
  2. enable the Let's Encrypt certificate resolver for that router (`tls.certresolver`), with the DNS/HTTP challenge the environment already uses;
  3. keep the OpsOne subdomain working (it remains the fallback and the platform host);
  4. set `APP_URL`/trusted hosts only if the environment pins them; the app already uses the verified host as canonical origin and in `sitemap.xml`/`robots.txt`.
  5. Removing the domain in the app only drops the record and the canonical switch; the server admin removes the router rule.

## Consequences
- New permissions are not needed: P-55, P-56, P-100, P-101 (staff) and P-72/P-83 (public) already exist in the catalogue; the `content` role holds P-55/P-56 only.
- `/` is no longer the starter welcome page. Two panel-surface assertions (`AppearanceTest`, `LocaleTest`) now probe `/login`, the other guest Inertia page.
- Out of scope here (other P8 sessions): store pages/cart, customer workspace and OTP login, coupons, tickets. The `store` and `account` slugs are reserved for them.
- Not done in this part: page-level analytics, per-section scheduling, automatic translation (never translates customer content), image cropping UI for the favicon.
