# ADR-0032: ZATCA (Fatoora) e-invoicing

- Status: accepted (2026-11-01) — engineering decision; the invoice trigger (§3) is a reversible default awaiting the
  product owner (open question Q-63).
- Scope: tax invoices (standard B2B / simplified B2C), credit and debit notes, the QR code, UBL 2.1 XML, hash chain,
  ECDSA/XAdES signing, EGS onboarding (CSR → compliance CSID → production CSID), reporting and clearance through the
  outbox, the owner's control panel. Phase 1 (generation) and phase 2 (integration) of ZATCA's e-invoicing rules.
- Related: ADR-0006 (audit), ADR-0007 (outbox), ADR-0013 (money, VAT Q-35 — **supersedes its "the tax invoice stays
  external (BR-20)"**), ADR-0014 (payments, receipts), ADR-0015 (refunds; M-40 external documents stay for facilities
  that keep an external accounting system), ADR-0029 (mPDF pipeline).

## Context

Selling OpsOne in Saudi Arabia requires that a facility can issue ZATCA-compliant e-invoices: phase 1 (structured
invoice with a TLV QR, no handwritten invoices) and phase 2 (UBL XML, cryptographic stamp, hash chain, real-time
reporting of simplified invoices within 24 h and clearance of standard invoices before they are shared). Until now the
tax invoice was external (BR-20). No real ZATCA credential exists; everything must work offline (tests, demo) and talk
to ZATCA's simulation/production gateway only when a deployment explicitly allows it.

## Decision

### 1. Implement the spec pieces in-house (no ZATCA PHP package)

The needed pieces are bounded and all are standard PHP: `ext-openssl` (secp256k1 keys, CSR with custom extensions via a
temp config file, ECDSA-SHA256), `ext-dom` (UBL XML, C14N, XSD validation), bcmath (amounts), Laravel `Http` (gateway).
All verified in the image (PHP 8.5.12, OpenSSL 3.5.7 has `secp256k1`). Community packages (e.g. `salla/zatca`,
`saleh7/php-zatca-xml`, `sevaske/zatca`) were considered and **not** used: single-maintainer projects without a
PHP 8.5 / Laravel 13 track record, pulling extra crypto/XML dependencies (phpseclib, xmlseclibs) for what openssl/DOM
already do, and they would sit on the most compliance-critical path where we must be able to read and fix every line.
The code lives in `app/EInvoicing/Zatca` (pure functions, unit-testable) and `app/EInvoicing` (domain services).
The QR PNG uses `bacon/bacon-qr-code` (already installed with Fortify).

### 2. Documents (`e_invoices`, immutable)

- One table for invoice (388), credit note (381), debit note (383); `type` standard (`0100000`) or simplified
  (`0200000`). Standard when the customer is an organization with a VAT number (unless the order asked for a simplified
  invoice); it then needs the buyer's **national address** (new `customers.national_address`, edited on the customer
  form) — without it the invoice is *blocked*, never issued half-valid.
- Gapless numbers from `GaplessSequence`: `INV-YYYY-000001`, `CRN-…`, `DBN-…`; a UUID; local issue date/time in the
  facility time zone; seller and buyer **frozen** as JSON; lines with net/VAT/gross; payment means (UNTDID 4461).
- **Immutable** (model guard): only the submission columns move. Corrections are notes referencing the original
  (`BillingReference`) with a mandatory reason (`InstructionNote`, KSA-10). A credit note never exceeds what is still
  creditable (invoice + debits − credits).
- VAT: the lines come from the record that fixed the price — the active agreement version (custom), the paid store
  checkout (coupon included) or the ready lines — so the per-line half-up rounding of Q-34/Q-35 (inclusive 15%) is kept
  and document totals are sums of rounded lines. Notes are one amount line split inclusive at the original rate.

### 3. When documents are issued (reversible default — Q-63)

- **Invoice**: automatically on the order's **first confirmed payment** (new after-commit event `PaymentConfirmed`
  from `PaymentPosting`), for the order's full priced value; one per order (`order_invoice_key` unique). The owner can
  also issue it manually. A blocked or failed issue never breaks the payment: it is logged without PII, listed on the
  settings page ("paid orders without an invoice") and retried hourly by `einvoicing:sweep`.
- **Credit note**: automatically on every executed refund (`RefundExecuted`, idempotent per refund — refunds already
  carry returns and cancellations), plus manual credit/debit notes by the owner.
- Only for payments confirmed after the owner switched e-invoicing on (`enabled_at`).

### 4. XML, hash chain, signature, QR

- UBL 2.1 `Invoice` per the ZATCA XML implementation standard (ProfileID `reporting:1.0`, ICV, PIH, QR reference,
  `cac:Signature`, parties, `Delivery/ActualDeliveryDate`, the two TaxTotals, monetary totals, lines with
  `RoundingAmount`; a price per whole quantity via `BaseQuantity` when the net does not divide evenly). Compact output.
- The bundled **OASIS UBL 2.1 XSDs** (`resources/zatca/xsd`, OASIS licence allows redistribution with the notice)
  validate every generated document in the tests, unsigned and signed. ZATCA's Schematron (BR-KSA-*) is not bundled
  (ZATCA SDK terms); it is exercised by ZATCA's own compliance endpoint during onboarding.
- **Hash**: remove `UBLExtensions`, `cac:Signature`, the QR reference; canonicalize (DOM C14N); SHA-256; Base64.
  **Chain** per EGS device: ICV from a gapless counter `zatca_icv:{device}` (the lock serializes concurrent issues), PIH
  = hash of the previous document of that device, the first one uses Base64(SHA-256("0")). Phase 1 documents (no
  device yet) form their own chain (device 0).
- **Signature** (once a device is active): ECDSA secp256k1 over the invoice digest, XAdES `SignedProperties` (signing
  time, certificate digest, issuer, serial) inserted as text so the stored bytes are exactly what was hashed.
- **QR**: TLV Base64 — tags 1–5 in phase 1; tags 6 (hash), 7 (signature), 8 (public key) and, for simplified
  invoices, 9 (certificate signature) in phase 2. A cleared standard invoice's PDF uses the QR from ZATCA's cleared XML.

### 5. Onboarding and keys

- Owner-driven (`ZatcaOnboarding`): fresh secp256k1 key + CSR (template `TSTZATCA-`/`PREZATCA-`/`ZATCA-Code-Signing`
  by environment, SAN with EGS serial, VAT number, `title=1100`, address, industry) → compliance CSID with the portal
  OTP → six compliance samples (standard/simplified × invoice/credit/debit) → production CSID. Resumable after a failure
  without a new OTP; a new onboarding or an environment change retires the live device (its documents keep pointing at
  it).
- The **private key and all CSID tokens/secrets are encrypted with APP_KEY** (Eloquent `encrypted` casts), hidden from
  serialization, never in a payload, the audit log or the application log (only step and error codes are logged).
  Backups carry them encrypted (ADR-0028); losing APP_KEY means onboarding again.

### 6. Gateway client and submission

- `ZatcaClient` with drivers: `fake` (in-process; signs CSRs with a throw-away test CA, verifies hash/signature, used by
  tests and the showcase), `simulation` and `production` (`HttpZatcaClient`: Fatoora endpoints, `Accept-Version: V2`,
  basic auth with the CSID, connect 5 s / total 20 s timeouts, no in-request retry). `ZATCA_ENVIRONMENTS` (default
  `fake`) lists what a deployment may use — a demo or test stack can never call ZATCA.
- Submission goes through the **outbox** (`OutboxChannel::Zatca`, key `zatca:{uuid}`): reporting for simplified,
  clearance for standard (303 → reported instead). Idempotent: only a `pending` document is sent, under its row lock;
  200/202 settle it (warnings kept), 409 = already received, 400 = **rejected** for good (corrected by a new document),
  401/403 = failed (CSID problem), 429/5xx/transport = retried with the outbox backoff (6 attempts), then **failed**;
  the owner may retry a failed document (`zatca:{uuid}:retry:{n}`).
- **Audit**: `einvoice.issued`, `einvoice.signed`, `einvoice.submitted` (each attempt), `einvoice.response` (each
  outcome, also "unavailable"), `einvoice.retry_requested`, `zatca.settings_saved`, `zatca.onboarding_started|onboarded|
  onboarding_failed`, `zatca.device_retired` — area `einvoicing`.

### 7. Control panel

`settings/einvoicing` (owner membership **and** P-02, never a support identity): seller tax profile with
`lock_version`, environment, enable switch (needs a complete profile), device status/checks/errors, OTP onboarding,
resume, paid orders without an invoice, the document list with status filter, retry, manual notes, PDF/XML downloads.
PDF/XML are also open to P-37 on the order; the order's finance tab lists its documents for P-37 holders.

## Consequences

- OpsOne is the issuer of tax invoices when e-invoicing is on; M-40 external documents remain for facilities that keep
  invoicing in an external system (they leave e-invoicing off).
- The full invoice value at the first payment is a simplification of the VAT tax point for advance payments; if the
  product owner wants one invoice per payment (advance invoices + final invoice), the issuer gains a per-payment mode
  and the chain/notes stay as they are (Q-63).
- Zero-rated/exempt lines, export and third-party/self-billing flags, summary invoices, multi-currency and VAT groups
  are not supported (all lines are standard-rated `S`).
- Rounding: lines keep the per-line half-up rounding; the document VAT is their sum. ZATCA's validator tolerates
  per-line rounding differences at document level; this is checked for real only by the compliance step of a live
  onboarding (see `docs/zatca-onboarding.md`).
- Going live needs a real compliance run against the simulation portal; until then the signing format (XAdES layout,
  C14N) is verified by our own verifier and the OASIS XSDs, not by ZATCA.
