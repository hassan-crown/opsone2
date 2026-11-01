# ADR-0029: Branded quote/contract PDFs and customer e-signature

- Status: accepted (2026-11-01)
- Scope: branded PDF of quotes and agreement-built contracts (ar RTL / en), the customer's electronic signature from
  the portal, staff download/print, signing links and signature status.
- Related: ADR-0006 (private attachments, audit), ADR-0013 (documents, content hash, customer decisions — Q-40 a),
  ADR-0021 (theme and logo), ADR-0022 (customer workspace, OTP), ADR-0025 (security baseline).

## Context

The product owner wants polished, branded documents and a customer signature from the portal to shorten the sales
cycle. Until now a customer approved a document from the portal (`CustomerDecisions`) or staff recorded an external
approval with evidence; there was no PDF. The PDF must render Arabic correctly (shaping, bidi) inside the existing PHP
image, and infra files (Dockerfile, compose, nginx, `manage.sh`) must not change.

## Decision

### 1. PDF engine: mPDF (PHP-native)

- **mPDF 8.3** (`mpdf/mpdf`, plus `setasign/fpdi` it ships with) renders Blade views (`resources/views/pdf/*`) to PDF.
  Its OpenType layout engine shapes Arabic (joining, lam-alef, marks) and handles bidi; it needs only `gd` and `mbstring`,
  both already in the image. Fonts: **IBM Plex Sans Arabic** TTF (Regular/Bold/SemiBold, OFL, `resources/fonts/pdf`;
  the family the app already self-hosts as WOFF2 — mPDF cannot read WOFF2) with its Latin glyphs, so one family covers
  both languages. Images go in as memory variables (`var:name`); mPDF never fetches a URL.
- **Why not a headless browser** (Chromium/Gotenberg, wkhtmltopdf): best CSS fidelity, but needs a browser binary in the
  image or a new compose service — an infra change this task may not make — and a network hop per PDF. **Why not
  dompdf**: no Arabic shaping (isolated letters). **TCPDF** (LGPL) shapes Arabic too but its HTML/CSS support is much
  weaker; it stays the fallback if the licence below becomes a problem.
- **Licence:** mPDF is GPL-2.0-only. OpsOne runs as a hosted service (ADR-0002: one deployment per tenant, operated by
  us); GPL-2.0 obligations attach to distribution, not to network use. If the product is ever *distributed* (on-premise
  copies to customers), revisit: replace with TCPDF (LGPL) behind the same `PdfRenderer` class or obtain legal sign-off.
- Verified in the PHP image (no infra change): Arabic headings, line items with mixed Arabic/Latin/digits and tables.

### 2. What a PDF shows and when it is generated

- `DocumentSheet` gathers everything from the sources only: the **sent content** (lines, discount, VAT-inclusive 15%
  breakdown from Q-35, totals, terms, clauses), the facility identity and **live theme** (logo attachment, brand colour of
  the published palette, default `#0E7C72`), the customer and order, the **payment schedule** (the order's dues once the
  agreement exists, else the facility's default template applied to the total) and up to 4 **design images** (JPEG/PNG
  of the approved — else awaiting — design document). Signable kinds: quotes, and contracts built from the agreement
  (an uploaded contract file is its own document and keeps the plain approval).
- **Fingerprint** = sha256 of all of it + `LAYOUT_VERSION`. A sent document keeps **one stored PDF per language**
  (`document_pdfs`, a private attachment of the document, `sha256` of the bytes). While the customer may still decide it
  is generated again only when the fingerprint changes (e.g. a new logo); once decided, replaced, cancelled or signed the
  last PDF is kept as is. A draft is rendered on the fly and never stored. Rows are immutable (model guards).

### 3. Signing (portal)

- The customer (guard `customer`, document through `PortalAccess`: another customer's id is 404) opens the PDF, types
  their name, draws the signature (PNG, size/dimension checked), ticks the consent (versioned wording), asks for a
  **step-up code** and submits. Step-up codes reuse `CustomerOtp` (6 digits, HMAC only, 5 min, 5 wrong tries burn it,
  same per-identifier/per-IP caps and lockout as sign-in, a 60 s per-document cooldown) with purpose `step_up` bound to
  `document:{id}`; a code is consumed in its own transaction, so it works once even if the signature is then refused.
- The submit carries the **content hash** and the **sha256 of the exact PDF** shown; a regenerated PDF is refused
  (`pdf_changed`). The signature is the customer's approval through the existing path —
  `CustomerDecisions::approveDocument(..., METHOD_SIGNATURE)` → `DocumentApproval::approve` (M-08-T02 transition record,
  content-hash and validity checks, agreement activation for a quote, P5 signals) — with, in the same transaction, the
  signature image (private attachment), the `document_signatures` row (signer name, customer, time, IP, user agent,
  content hash, PDF id + sha256, OTP challenge id and channel, consent version, link used) and the audit entry
  `agreement_document.signed` (document, hashes, OTP reference — no name, no IP: the audit stores hashed IPs only).
  One signature per document (unique), and `approval_key` already makes a second approval impossible.
- **Signed PDF**: the signed PDF's pages imported unchanged (FPDI) plus a **certificate page** (signer, account with
  masked mobile, time in the facility timezone, OTP channel and reference, IP, user agent, content hash, PDF sha256,
  references, consent text, drawn signature). Generated right after the commit; if that fails it is generated on the
  next download — the signature stands.

### 4. Staff

- `GET /agreement/documents/{id}/pdf?locale=ar|en[&download=1][&version=original]` — needs the order (P-16) **and** its
  amounts (P-37, or P-29 who prices), the same rule as the agreement payload; draft = preview, sent = stored PDF,
  signed = signed PDF. Every download is audited.
- **Signing links** (P-30): a 48-character random token (only its sha256 stored), expiring after 7 days or at the end
  of the document's validity, sent by SMS/email through the outbox. It only opens the document inside the portal —
  sign-in is still required and only the order's customer gets past it (others see «not valid», 404; expired or revoked
  → 410 page). A new link revokes the previous ones; signing marks it used and revokes the rest; staff can revoke.
- Void and re-issue stay the M-08 rules: an unsigned document is cancelled (T08) or replaced (revise → T04); a signed
  document is approved — it can't be edited (model guard), cancelled (T08 excludes approved) or re-signed; a change
  goes through a replacement contract (T07) or an agreement change (M-09).

## Consequences

- New tables: `document_pdfs`, `document_sign_links`, `document_signatures`; `customer_otp_challenges.subject` (expand
  only). New audit actions `agreement_document.signed|sign_link_sent|sign_links_revoked`.
- Generated PDFs count against the storage quota (`storage_mb`) like any attachment.
- PDF rendering takes ~0.3–1 s; it happens on first view/download of a sent document per language, then is cached.
- ShowcaseSeeder: two portal accounts sign their quote (one also its contract), one quote and one contract await
  signature with a live link; their designs carry JPEG renders shown in the PDF.
- Out of scope: qualified e-signature / PKI-signed PDFs (a certificate page is evidence, not a cryptographic PDF
  signature), facility CR/VAT numbers (still a data gap from ADR-0013 — shown when the contract carries them).
