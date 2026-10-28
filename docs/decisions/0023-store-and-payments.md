# ADR-0023: Ready-goods store, checkout, Moyasar, basket links and coupons (P8 part C)

- Status: accepted (2026-10-28).
- Scope: domain 07 — SRS-WEB-05, WEB-06, WEB-07 (pickup only), FIN-07 (customer side), SAL-01..07, SAL-17, SAL-18,
  SUB-08..10 (facility side); permissions P-69, P-83, P-91, P-92, P-93 (+ P-02 for the store and payment settings);
  machines M-05-T02..T07, M-11-T00/T02..T06/T08 (online), M-24-T00/T02, M-31, M-32; process P07; screens `StoreList`,
  `StoreProduct`, `StoreCart`, `Checkout`, `CheckoutPending`, `Coupons`, `CouponNew`, `LinksMobile`, `StoreSettings`.
  Decisions Q-45 (Moyasar, product owner 2026-10-28) and Q-48 (a).
- Builds on: ADR-0011 (orders, gapless numbers), ADR-0013 (money, Q-34 a / Q-35 a), ADR-0014 (`PaymentGateway`,
  verified-webhook-only money), ADR-0016 (sale ownership), ADR-0017 (stock ledger, reservations), ADR-0019 (ready-order
  money on the manual path), ADR-0020 (settlement follow-up), ADR-0021 (public site surface, theme), ADR-0022 (customer
  guard and OTP).
- Not in this release: delivery zones and fees (WEB-07 is branch pickup only), live Moyasar keys, commission accrual on
  ready orders (the sale is pinned; see «Follow-ups»), a staff discount on links (SAL-08/09: links sell at catalogue
  price, coupons are the only discount), editing a link's quantities by the buyer, product images in the store.

## Decision

### Surface and routes

- The store is part of the facility's public site: server-rendered Blade on `data-surface="site"` with the published
  Alpha theme, the site's header and one URL per language (`/{ar|en}/store`, `/p/{id}`, `/cart`, `/checkout`,
  `/checkout/{reference}`, `/l/{token}`). `routes/store.php` is loaded by `StoreServiceProvider`, registered **before**
  `SiteServiceProvider`, so the reserved slug `store` never reaches the site's page route.
- Public store routes run in the P8b `customer` middleware group (customer session cookie, CSRF) plus `SiteLocale`:
  the guest cart (variant → whole quantity, max 20 lines × 99) lives in that session only and never holds stock.
- Staff screens are Inertia pages under `/store-admin` (`store/links`, `store/coupons`, `store/coupon-new`,
  `store/settings`), nav item `store` shown with P-91 or P-92.
- The P8a showcase placeholder (`site.sections.ready_goods`) now lists up to 8 store products through a view composer,
  only while the store sells.

### When the store sells (`StoreGate`)

- Selling = license allows `ready_goods` in full mode **and** `store_settings.enabled` with an active receiving branch.
  The license is read per call (a cached controller never keeps an old license). Otherwise every store page, link and
  checkout is 404 / `store_closed`, coupon and link administration refuse (P-91/P-92 already carry the capability).
  What started before (a pending payment) is still settled from its own source; restoring the capability (SUB-10)
  reopens the store as configured (scenario 15).

### Checkout (Q-48 a) and the ready order

- The buyer proves a mobile with a 6-digit code (`CheckoutOtp`, the P8b policy and caps: hashed code, 5 minutes,
  5 attempts, send/failure caps per identifier and IP). SMS when the facility has it; otherwise e-mail — the account's
  own address for an existing customer, the typed address for a new buyer. A proven mobile **links** the existing
  customer (opening the workspace login) or **creates** a person customer in the store's branch; the buyer is then
  signed in to the customer guard, so the order is followed in «حسابي». `customer_otp_challenges.customer_id` became
  nullable for a code sent to a mobile with no account yet.
- `StoreCheckouts::start()` is one `LockingTransaction`: store selling → link attempt (M-31-T02) → lines re-checked at
  current prices → order opened by `OrderOpener::openFromStore()` (M-05-T00 without a staff actor, `created_by` null,
  assignee = the link's owner or the store handler) → `ReadyOrderPayment::start()` (M-05-T02: every line reserved from
  the store location under the balance locks in one global order — the last unit cannot be sold twice, scenario 3) →
  checkout row `CK-YYYY-000001` with the fixed price breakdown → coupon use reserved under the coupon's row lock
  (M-32-T01) → pending payment (M-11-T00). Only after the commit is Moyasar called; if it cannot create the invoice,
  everything is released at once and the payment cancelled (M-11-T06). The same idempotency key (per customer) returns
  the same checkout and invoice.
- The order total is the payable total after the coupon; each line's discount contribution stays in
  `store_checkouts.pricing` (returns and commissions use the actual value, SAL-07).
- `CheckoutPending` shows the server's state only (refreshes itself); no query string or return URL confirms anything.
  Only the signed-in buyer can open or cancel their checkout (other references are 404).

### Money: coupon before VAT

- `StorePricing` follows ADR-0013 (Q-34 a, Q-35 a): the coupon applies to the eligible lines' value **before VAT** (the
  net of an inclusive price), percent half-up or a fixed amount capped at that value, allocated by net without losing a
  halala; VAT is then computed per discounted line, half-up; totals are sums of rounded lines. A line without discount
  keeps exactly its entered amount. Example: 115.00 inclusive, 10% → net 100.00 − 10.00, VAT 13.50, total 103.50.

### Moyasar (Q-45) behind `PaymentGateway`

- `MoyasarGateway` creates an **invoice** (hosted payment page) server side for the exact pending amount with
  `expired_at` = reservation end + 10 minutes, keeps the invoice id in `payment_gateway_sessions`, and sends the buyer
  back to `CheckoutPending`. `MoyasarClient` uses Laravel's HTTP client with connect/total timeouts; reads retry twice
  on a connection error or 5xx; creating an invoice never retries (a lost answer must not leave an unknown payable
  invoice). Tests fake every call with `Http::fake()` and `preventStrayRequests()`.
- Webhooks use the existing `/webhooks/payments/{gateway}` route (no session, no CSRF, throttled). Moyasar does not sign
  the body with an HMAC; it carries the dashboard's `secret_token`, compared in constant time. A `live` event while in
  test mode is refused. The body only names the payment: its status, amount, currency, invoice and time are **re-read
  from Moyasar with the secret key** before anything is applied. A payment in progress, or a declined card while the
  invoice can still be paid, is acknowledged and ignored (not a final failure). An unknown/unreachable payment answers
  503 so Moyasar retries.
- `GatewayPayments::applyVerified()` is now the single path for a verified result (the webhook, or the expiry
  service's own `fetch()`); the amount and currency must match or the payment becomes unresolved; a repeat is M-11-T08.
  `GatewayOutcomes` (bound to `StoreGatewayOutcomes`) runs in the same transaction under the order lock: confirmed →
  M-05-T03, coupon consumed (M-32-T02), link used and sale pinned; unresolved / mismatch → M-05-T05 with the reservation
  kept; a settled failure → released like an expiry.
- Credentials (SRS-SET-06): `integration_settings` key `moyasar`, encrypted `config` (publishable key, secret key,
  webhook token), never serialized to the browser; the settings screen shows only whether a secret is saved. **Test
  mode only**: keys must be `pk_test_…`/`sk_test_…`; a live key is refused. Without facility settings, TEST values from
  env (`MOYASAR_TEST_*`, `config/services.php`) are used for local work; nothing is committed.

### `FinanceLock` (ADR-0019 revisited)

- `READY_PATHS['M-11']` is now `T00, T01, T02`: besides the manual T01, a ready order takes online money only through
  the store's pending payment (`createPending` inside the checkout transaction; the portal's `begin()` refuses ready
  orders) and its result only through `applyVerified()` — a Moyasar webhook whose token was verified and whose payment
  was re-read, or Moyasar's own record read server to server. Proofs, provider refund results, corrections, transfers,
  schedules, disputes and documents still refuse ready orders.

### Reservation window and late results (WEB-06, M-05-T04..T07)

- `store:expire-checkouts` (scheduler, every minute, `withoutOverlapping`) settles ended windows from Moyasar's answer
  first: paid → confirmed; nothing paid → M-05-T04 (order cancelled, reservations released, coupon use released, link
  given back or expired); Moyasar unreachable → nothing released (no fake failure), and 30 minutes later M-05-T05 with
  the reservation kept and the handler notified. The pending payment stays open during the invoice's grace and is
  cancelled once Moyasar reports the invoice ended.
- A payment that succeeds after the window: the money is posted (one receipt); the order goes M-05-T06 → T07 when every
  line can be reserved again (system resolution), otherwise it stays «needs attention». After a cancellation the
  **buyer chose** (abandon), the order stays cancelled (scenario 5). Without a sale, a `refund` item opens on the order's
  settlement follow-up (ADR-0020) for a refund made through P6 M-12 by P-43, and the handler is notified — never oversold.

### Basket links (M-31) and coupons (M-32)

- A link: 43-character random token (only its SHA-256 is looked up; the encrypted token is kept so its owner can copy it
  again), number `CL-YYYY-000001`, default 24 h up to the facility maximum (7 days), lines only (no price, no customer
  data shown). Issuing never reserves or creates a sale. The owner is the issuing member, read from the record at
  purchase — no request field can set or change it (SAL-05). One checkout attempt at a time (`checking_out`); a
  settled failure gives it back (or expires it). The sale is pinned to the owner only if the provider's payment time is
  inside the validity and the owner is still active and allowed (SAL-03, SAL-18); a late notice of an in-time payment
  keeps the sale. Cancel / replace need P-93 and no attempt in progress; a replacement voids the old token first.
- Coupons: code 3..32 `[A-Z0-9-]`, unique upper case; percent ≤ 100% or a positive amount; start/end; variant scope;
  minimum subtotal; total and per-customer caps (per verified customer). `uses_count` counts reserved + consumed and is
  checked under the coupon row lock (two racing checkouts cannot both take the last use). Pause stops new uses at once
  and honours a reserved use. A released use consumed again by a late payment keeps its fixed discount (M-32-T05).

### Writes and records

- Every write is a transaction with row locks (order → finance head → balances → coupon/link), `lock_version` on
  coupons, links and settings, idempotency on checkout start and on the webhook (M-11-T08), `state_transitions` for
  M-05, M-11, M-24, M-31, M-32 and audit events (`store.*`, `coupon.*`) without secrets. Staff notices
  (`store.order_paid`, `store.needs_attention`) go through the notifier and outbox.

## Consequences

- Ready orders can now be paid online; the made-to-order money paths are unchanged.
- `PaymentGateway` gained `fetch()` (server-side re-read) and `GatewayUnavailable` / `IgnoredGatewayEvent`; test
  stand-ins implement `fetch()` returning null.
- `OrderLineBuilder::build()` accepts a null actor (a line the customer chose).

## Follow-ups

- Commission accrual for ready orders: `CommissionBasis` reads the agreement and dues, which ready orders do not have,
  so a link sale is pinned (owner, rate) but accrues 0 until the basis learns the checkout's eligible net and the
  order's collected credit (P6 owner decision).
- Delivery zones and fees (WEB-07), live keys after a production review of the Moyasar account, product images on the
  public store (public media for catalogue files), a staff screen listing store checkouts needing attention.
