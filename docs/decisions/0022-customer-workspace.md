# ADR-0022: Customer workspace with passwordless sign-in (P8 part B)

- Status: accepted (2026-10-28).
- Scope: domain 07 — SRS-WEB-02, WEB-03, WEB-08..WEB-13 (customer side), FIN-05 (customer side); permissions P-65..P-68,
  P-70, P-99 (customer); machines M-08-T02/T03 from the account (P6a `CustomerDecisions`), M-10-T00/T03/T04 (P6b
  `CustomerProofs`), M-16-T02 from the account and the customer change request, M-34-T01 from the account and T05;
  screens `Cust*` (home, orders, order, documents, updates, settings), `ActivateAccount`, `DocApprove`, `PayOrder`
  (manual transfer proof), `CancelRequest`. Decision Q-47 (a)+(b).
- Builds on: ADR-0005 (staff Gate), ADR-0006 (private files, signed links), ADR-0007 (outbox), ADR-0009 (customers,
  `portal_login_enabled` Q-27), ADR-0012 (appointments), ADR-0013 (customer decision, Q-40 a), ADR-0014 (proofs),
  ADR-0020 (cancellation request record).
- Not in this phase: the store, cart, online payment (P-69, Q-45) and tickets (P-71) — next wave; the public site and
  theme (parallel session). Organization customers' contact persons do not get their own logins (see below).

## Decision

### Separate guard and session

- Guard `customer` (session driver) over `App\Models\Customer` (now `Authenticatable`, no password, no remember-me).
  Its user provider is registered under its own driver name (`portal-customers`), so the staff guard's type stays
  `User` everywhere (Larastan) and `$request->user()` is never a customer.
- Customer routes live in `routes/customer.php`, loaded by `PortalServiceProvider` under `/portal`, names `customer.*`,
  middleware group `customer`: cookies, **`UseCustomerSession`** (renames the session store to `<session cookie>_portal`
  before `StartSession`), session, errors, CSRF, bindings, `SetPortalLocale`, appearance, `HandlePortalInertiaRequests`.
  None of the staff web middleware runs (no `EnsureAccessIsCurrent`, support access, license banner, staff nav or bell).
  A customer request therefore never reads, writes or rotates the staff session cookie, and the reverse.
- `AuthenticateCustomer` checks the `customer` guard **without** switching the default guard, so no staff check can
  ever see a customer; the staff `auth` middleware never sees a customer either. Staff routes answer a customer with the
  staff login redirect; customer routes answer staff with the customer login.
- The portal Inertia middleware flushes shared props first and shares only `name`, locale/direction, the `portal.*`,
  `ui.*` and `orders.kind.*` strings, the facility's public name and the customer's display name.
- Known edge: both sides share the `XSRF-TOKEN` cookie name; staff and customer tabs open in one browser can make the
  other side answer 419 once (Inertia reloads). Acceptable for v1; a separate domain for the site removes it.

### Passwordless sign-in (WEB-08, Q-47 a+b)

- Identifier: the account's mobile (normalized to E.164, local formats accepted) or its email. Only accounts with
  `portal_login_enabled` can sign in. **Channel**: SMS when the facility has SMS configured (`SmsChannel::isConfigured()`)
  and the customer typed the mobile; otherwise the email **on the account** — never an address the customer types. A
  successful email code marks `email_verified_at` (receiving the code proves the address); a mobile code never verifies
  the email.
- Codes: 6 digits, valid 5 minutes, one use; only `HMAC-SHA256(app key, identifier hash | code)` is stored in
  `customer_otp_challenges`. A newer code supersedes the open one. Messages go through the outbox (payload encrypted at
  rest and erased once sent, ADR-0007).
- Caps (`CustomerOtp`), all with a 15-minute window and the same answer for every identifier: 1 send per 60 s and 3 per
  identifier, 10 per address; 5 wrong codes burn a code; 10 failures lock the identifier and 30 the address for the
  window (audited `portal.sign_in_locked` when the account exists). An outer route throttle bounds each address to 30
  requests/minute.
- **No enumeration**: an unknown, disabled or code-less identifier gets the same redirect, page and errors as a real
  one, counts against the same caps, and simply receives nothing.
- Sign-in regenerates the session id and the CSRF token (no fixation), stamps the session with
  `customers.portal_session_version`, sets `portal_activated_at` on first sign-in, and is audited (`portal.signed_in`
  with channel and address). «Sign out everywhere» bumps the version; `AuthenticateCustomer` ends any session with an
  older stamp, or whose account lost `portal_login_enabled`.
- **ActivateAccount**: staff with P-10 over the customer send a signed activation link (7 days, SMS + email,
  audited `portal.invited`) from `POST customers/{customer}/portal-invite`. The link only opens the page; a code to the
  account's own mobile/email is still required. Staff creating a customer never activates the account (WEB-08).

### Isolation (WEB-02)

- Every customer query starts in `Portal\PortalAccess`, scoped by the signed-in customer id: orders of the customer
  (except a ready-goods draft), documents once sent, their own proofs, receipts of their orders, appointments once sent
  for confirmation, their cancellation requests. Route ids are looked up inside the scope (no implicit binding): a
  foreign or malformed id is **404**, never 403. The domain services re-check ownership (`CustomerActor`).
- Files: `PortalFiles` signs short-lived links bound to the customer (`c`); the download re-checks that the file's
  record (a proof or a sent document) is still the customer's and is audited.
- Payloads (`PortalPresenter`) are allow-lists: no internal notes, staff names or assignees, departments, task lists,
  costs, commission flags (`commissionable`, `products_minor`) or line ids. Stages appear only with
  `show_to_customer`, by their public name.

### Customer actions

- **DocApprove** uses P6a `CustomerDecisions` with the content hash shown; changed content is refused
  (`content_changed`). A repeated approval of the same content (double tap, second tab) is answered as done and never
  records a second decision.
- **Transfer proof** (P-68) uses P6b `CustomerProofs` (submit, resubmit after a correction request, withdraw). Proofs
  under review are shown apart and never counted as paid. Accepted only once the order has an agreement.
- **Appointments** (P-70): confirm an appointment awaiting confirmation (M-16-T02 from the account, method
  `customer_account`, same member-locked conflict check as staff; a customer can never confirm over another booking —
  refused with a neutral message). Change request: the original stays booked; the request (reason and preferred time,
  free text) is stored on `appointments.customer_request`, audited and notified to the creator and the order's
  assignee; staff answer with M-16-T03, which clears it. No published facility slots exist in v1, so the customer never
  books a time directly (WEB-11 «فترات أتاحتها الجهة» waits for a slots feature).
- **CancelRequest** (P-99): M-34-T01 with source `customer_account`, whole order, reason required, one active request
  per order; a custom order becomes `cancellation_pending`; the assignee is notified. T05 withdrawal before the
  decision returns the order to its previous state. Staff decide through the existing impact review (ADR-0020).
- **Updates**: derived from the records (documents sent/decided, receipts, proof decisions, appointments, cancellation
  decisions) so they always match what the customer sees; no separate customer notification table. SMS/email messages
  to the customer stay with their domain services.

## Consequences

- One customer identity per facility (no central identity, ADR-0002). An organization customer signs in with the
  account's own mobile/email; contact persons get no separate access until «the authorized person chooses who may
  see orders» is built (domain 07).
- Changing the mobile or email needs ownership proof; in v1 it goes through the facility (P-10), not self-service.
- Facility bank-transfer details are not configurable yet; the pay screen says «pay by bank transfer» without an IBAN
  (follow-up).
- New tables/columns: `customer_otp_challenges`; `customers.portal_activated_at|email_verified_at|
  portal_session_version|portal_locale|portal_last_login_at`; `appointments.customer_request` (additive migration).
