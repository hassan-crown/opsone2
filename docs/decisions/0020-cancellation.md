# ADR-0020: Order cancellation with impact review and the settlement follow-up (P7e)

- Status: accepted (2026-10-28).
- Scope: domains 02/06 — SRS-COM-13/14, WF-13, FUL-12, FIN-14, INT-08; machines M-34 (cancellation request, staff
  side: T01 record, T02 accept, T03 record and accept, T04 reject), M-04-T04/T05/T06, M-05-T09; process P03; the
  mandatory scenario «إلغاء ورد» and «تسليم2 من5 ثم إلغاء الباقي»; screen 115 `CancelReview`. Permission P-18 over the
  order (scopes own … tenant; roles owner, manager). Amounts need P-37, supplier costs P-82.
- Builds on: ADR-0011 (order lock, gapless numbers, idempotency), ADR-0012 (engine: tasks, appointments, `WorkGuard`),
  ADR-0013/0014/0015/0016 (agreement versions, schedule, refunds M-12, commission accrual and holds), ADR-0017
  (reservations M-24), ADR-0018 (delivery serializes on the order row).
- Not in this phase: the customer's own request and withdrawal from their account (M-34-T01 by P-99, T05 — portal,
  P8; the Actions accept `source = customer_account`), the comprehensive closure M-04-T07 / M-05-T10 (they will read the
  settlement follow-up as a closure blocker), returns (P-53, parallel session — wired to the follow-up at merge).

## Decision

- **Request record** `order_cancellations`: one row per decision, `CN-YYYY-000123` (gapless, taken when recorded),
  scope `full | partial`, source (`external` with a channel, or `customer_account`), date, reason, requested lines,
  the value kept on a full cancellation (`retained_minor`), the order state before the request, and — once accepted —
  the applied impact and its fingerprint. One active request per order (`active_key` unique). Recording a request
  (T01) moves a custom order to `cancellation_pending` (M-04-T04): `WorkGuard::running` and delivery then refuse new
  work (WF-13) while what was done, collected or delivered stays. Rejecting (T04 + M-04-T06) returns the order to the
  recorded state and lifts only that suspension. Recording and accepting at once (T03) needs no second employee and no
  customer login (COM-14); when a request is already pending, that request is the one decided.
- **Impact review** (`Cancellation\CancellationImpact`, read only): what stops (open tasks, proposed / awaiting /
  confirmed appointments, stages that will not run, an open agreement change that can no longer apply), what stays
  (delivered quantities, issued materials from the stock journal, completed stages, net collected), and what needs
  settlement (value before/after, collected, refunds still reserved, the refundable amount, reservations to release,
  open purchase orders with a line for the order, the commission difference). The confirm sends back a **fingerprint**
  (`hash`) of the plan — identities, quantities and amounts, never translated text — and the order `lock_version`.
  Under the locks the impact is computed again; a different fingerprint is refused `409 preview_stale` (a delivery, a
  payment or a task change in between), a different version `409 stale_version`. Preview and applied result are the
  same computation; the applied impact is stored on the request.
- **What can be cancelled** (`CancellableLines`): ready lines up to what their open reservations still hold (before
  confirmation, the ordered quantity); a custom order with an agreement by **agreement line** (`a{index}`), up to the
  line quantity minus what was delivered on the linked order line; a custom order without an agreement by whole order
  lines only (no price to recompute; the line is marked removed). A partial cancellation that would leave nothing to
  deliver and nothing delivered must be a full one. A fully delivered order is refused (`fully_delivered`): that is a
  return or a correction (M-04-T04).
- **Apply (all or nothing, one transaction, order row first)** — `Cancellation\CancellationApply`:
  1. full cancellation of a custom order: open tasks → cancelled (M-07-T07, cause `cancellation`, without the
     `required_result` guard: the cancellation is the decision that the result is not needed) and upcoming appointments
     → cancelled (M-16-T06, markers refreshed, members notified); stages keep their state — a cancelled order runs
     nothing;
  2. stock: open reservations released through M-24 (`T02ReleaseReservation`, now with an optional quantity for a
     partial release — the reservation stays open for the rest and a later full issue ends `settled_mixed`); what was
     issued never returns to stock here (FUL-02);
  3. cancelled quantities recorded in `order_cancellation_lines` (custom lines: the effective quantity for delivery is
     ordered − delivered − cancelled, read by `DeliveryProgress`);
  4. money through P6 contracts: the agreement is **re-priced** by `QuotePricing` (quantities reduced; a percentage
     discount kept, a fixed discount in proportion to the remaining base, half-up; a full cancellation keeps the
     delivered quantities plus the retained value as a non-commissionable line) and activated as the next version by
     `AgreementActivation` with a `customer_decisions` row (`subject_type = cancellation`, the request's method and
     channel — the customer's request accepted by P-18). `OrderSchedule::sync()` runs in the same transaction (dues
     follow, allocations trimmed to credit), then `CommissionAccrual::recompute(source cancellation:CN-…)`. No agreement
     is created for an order cancelled before its agreement («لا يشترط اتفاقًا صفريًا»). A ready order's `total_minor`
     follows the remaining lines;
  5. order state: custom full → `cancelled` (M-04-T04 recorded too when accepted directly, then T05); partial → the
     state before the request; ready → M-05-T09 (`cancelled`, or `delivered` when something was delivered and nothing is
     left);
  6. the settlement follow-up (below), M-34 transition, audit (`cancellation.*`), outbox messages to the customer and
     an in-app notice to the assignee (link re-checks P-16 on the request).
- **No automatic refund** (P-18, FIN-14): the refundable amount = max(0, collected − value kept − refunds still
  reserved) becomes a `refund` item; the refund itself is requested and executed through M-12, whose limit is reserved
  under the order lock there. Collected money or a pending refund never blocks the acceptance.
- **Settlement follow-up** (`App\Settlement\SettlementFollowUps`, INT-08): **one per order** (`settlement_followups`
  unique `order_id`) with items `refund | stock_release | supplier | commission | return`, opened once per
  `source:kind:ref` (`item_key`). Items follow their source: a refund item is done when executed refunds since it was
  opened cover its target (FIFO over refund items; `RefundExecuted` listener + every read of the screen), a supplier item
  when its purchase order is received or its remainder cancelled; stock and commission items are recorded done (what the
  decision did). A person may settle an item otherwise (`done` or `dropped`, with a note) with the permission of the
  action it stands for — P-43 refund, P-46 supplier, P-18 otherwise. The follow-up closes only when no item is open and
  reopens when a later source adds one. Nothing is opened when nothing remains (P03: no fictitious settlement).
  Commission payouts are held as `cancellation` while a refund item is open (decorates the 6c `SettlementHolds`).
- **Concurrency**: the cancellation and a delivery both lock the order row first; the second re-reads — a delivery
  after the cancellation is refused `order_cancelled`, a cancellation after a delivery `preview_stale`. Issued plus
  released never exceeds a reservation. Two decisions on the same order: one applies (the other `not_from_accepted` or
  the idempotent replay).

## For returns (P-53)

`app(SettlementFollowUps::class)->add($order, SettlementItem::RETURN|REFUND, 'return:{id}', $details, $targetMinor,
$refType, $refId, $done)` under the order lock, then `->sync($order)`; mark a return item done by resolving it or by
adding it done when the return's own transition completes. See `docs/p7-inventory-api.md` «Cancellation».

## Consequences

- An accepted cancellation changes the agreement only by a new version; the earlier versions, payments, receipts,
  deliveries and commission entries stay as they were.
- `T02ReleaseReservation::handle()` gained an optional last parameter; existing callers are unchanged.
- `DeliveryProgress` subtracts accepted cancelled quantities on custom lines.
