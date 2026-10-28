# ADR-0013 — Money foundation and the agreement inside the order (P6a)

Status: accepted — Q-35 a decided by the product owner (2026-10-28: VAT-inclusive prices by default, 15%, changeable per
facility); Q-34 a, Q-36 c, Q-40 a as reversible defaults

> Service and HTTP contract for 6b/6c/6d and the screens: [`docs/p6-finance-api.md`](../p6-finance-api.md).

## Decision

### Money (all of P6)

- **`App\Money\Money`**: immutable integer minor units (SAR halalas), currency fixed to the facility's (`SAR`).
  No float carries an amount anywhere (server or client); arithmetic is bcmath on decimal strings. Every result stays
  within ±`MinorUnits::MAX` or throws `MoneyOverflow` before anything is stored (never saturated or wrapped).
- **One rounding policy (`App\Money\Rounding`, Q-34 a)**: half-up, away from zero, applied once per line; totals are
  sums of rounded lines, never a rounding of the total. `Money::times(quantity)`, `percent(bp)` and `ratio()` all go
  through it. Commission rounding happens at accrual (6d) with the same class.
- **Allocation (`Money::allocate(weights)`)**: largest remainder — each part gets its truncated share, the halalas left
  go one by one to the largest remainders (earlier parts on ties); the parts always sum exactly to the amount. Used to
  spread a document discount over its lines (so VAT stays per line) and, later, collections over dues.
- **VAT (Q-35 a, decided by the product owner 2026-10-28; `App\Money\VatCalculator`)**: rate per facility in basis points (default 1500 = 15%), entered prices
  VAT-inclusive (default) or exclusive, computed per line on the discounted line amount: inclusive
  `vat = amount × r / (1 + r)`, exclusive `vat = amount × r`, both half-up. The tax invoice stays external (BR-20).
- **Facility settings**: `finance_settings` (one row, defaults until saved; `lock_version`): `vat_rate_bp`,
  `prices_include_vat`, `quote_validity_days` (14), `contracts_enabled` (true). Edited with P-02 in `SettingsSales`;
  a change never re-prices a sent document (each document stores the rate and basis it was priced with).

### Agreement (domain 04)

- **Quote inside the order (AGR-01)**: `agreement_documents` kind `quote`, created from the order's active lines (the
  product's reference price only as a suggestion; an unknown price stays `null`, never 0). One open quote per order
  and none once an agreement exists — later value changes go through M-09. Ready orders sell at their published
  price: agreements are for made-to-order orders in v1 (refused as `ready_order`).
- **Discount limit and referral (AGR-02, M-26)**: `discount_limits` per role template or member override
  (`custom_bp`, `ready_bp`, `approval_bp`); the owner has none and alone sets the others'. Exceeding the sender's own
  limit blocks sending (`discount_needs_decision`) until a `discount_decisions` request on the **exact priced content**
  (hash of lines, discount, VAT, totals) is approved by someone else holding P-31 in scope with an approval limit at
  least the requested share. Any change to the priced content supersedes an approved decision (M-26-T04) or cancels a
  pending one (T03). Limits compare `discount × 10000 > limit × base` exactly (no rounding).
- **Documents (M-08)**: quote, design (private attachment, P-33), contract (P-32, from the active agreement with party
  data copied in, or an external file — **no contract templates in v1, Q-36 c**). A draft changes freely (audit log);
  sending (P-30) fixes `content` + `content_hash` (the model refuses edits afterwards). A different content is a
  replacement (`replaces_id`): sending it replaces an open predecessor (T04); approving it replaces an approved one
  (T07). Quotes expire after their validity (`agreements:expire-documents`, daily, T05); T06 extends the same
  content; T08 cancels with a reason. Approved documents never expire, are never cancelled.
- **Customer decisions (Q-40 a)**: `customer_decisions` (append-only) bound to the content hash the customer saw:
  from the customer's own account (`App\Agreements\CustomerDecisions`, for the P8 portal) or an **external approval**
  recorded by P-34 with channel, date, note and evidence file (a private attachment of the decision). It satisfies the
  approval itself — no second review — and replaces a signature; no e-signature provider. `approval_key` (unique)
  makes a second approval of the same subject impossible even under concurrency.
- **Agreement versions (AGR-01/07/09)**: approving a quote writes `agreements` version 1 on the **same order**
  (`orders.total_minor` follows the active version). Versions are immutable (model guard); a change writes the next
  version and only marks the previous one `superseded`. One active version per order (`active_key` unique).
- **Agreement change (M-09, AGR-06/07, P02)**: one open change per order, starting from the active content. Presenting
  it (P-36) fixes its content/hash, the difference and extra days and **holds the affected work** listed by the person
  (`order_actions.held_by_change_id`: its tasks cannot start or complete — `held_by_agreement_change`; other work
  continues). Applying (customer account, or P-36 + P-34 with evidence) writes the new version once, releases the
  hold, marks the approved quote replaced and records `settlement_needed_minor = max(0, collected − new total)`
  through `CollectedAmounts` (bound by 6b) — **never an automatic refund** (AGR-06). Approving content older than the
  latest presentation or built on an older agreement is refused (`content_changed`, `agreement_changed`).
- **P5 wiring (result owner)**: a sent quote/contract verifies `prepare_document` with that document; an approved
  design/quote/contract verifies `customer_approval` with that subject, through `ActionResults::verifyFromSource()`
  (`source = agreement_document:{id}`). Results recorded before their stage apply when the action opens.
- **Writes**: every money-affecting write takes an idempotency key (`AgreementWrite` over `Support\Idempotency`: a
  replay returns the first result; same key + other payload → 422) and the `lock_version` the screen read (409
  `stale_version`). Each write locks the order row first (same lock as the engine), checks the machine, writes
  `state_transitions` (M-08, M-09, M-26) and `audit_events` in one transaction.
- **Amounts hidden by grant**: the agreement payload carries amounts only for P-37 (or P-29, who prices); otherwise
  every `*_minor`, `discount`, `totals` and `presentations` key is removed server-side (`amounts_visible: false`). The
  P-31 decider sees the base, discount and total of the request being decided only (matrix P-31).

## Consequences

- 6b reads `OrderAgreement::value()/share()/productsValue()`, listens to `AgreementApplied` (after commit) and binds
  `CollectedAmounts`; 6d reads `products_minor` (commission-eligible lines) per version.
- A stored evidence file is written before its row commits; if a later step of the same transaction fails the file
  may stay orphaned on the private disk (same trade-off as `AttachmentService`). It is never reachable without a row.
- Reversible: contract templates (Q-36 b) can be added later as another `ContractContent` source; an e-sign provider
  would be another `CustomerDecision` method.

## Not in 6a

Allow-to-proceed requests from non-holders (AGR-08 / M-26 for P-35: P5's direct `T04AllowProceed` stays), the customer
portal screens (P8), ready-goods staff discount and coupons (P8), collections, dues, refunds and commissions (6b–6d).
