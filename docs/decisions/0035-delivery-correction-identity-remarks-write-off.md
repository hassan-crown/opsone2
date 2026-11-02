# ADR-0035: Delivery correction, staff identity, multiple delivery remarks and debt write-off (product owner's decisions)

Status: accepted (2026-11-02). Implements the product owner's answers to the four questions ADR-0032 and
`docs/acceptance-report.md` §8 left open: M-14-T03, Q-03, M-14-T04 (several remarks) and the debt at closure (M-04-T07 /
M-05-T10). Builds on ADR-0013..0016 (money), ADR-0017 (inventory), ADR-0018 (delivery), ADR-0021 (theme), ADR-0032.

## 1. Correcting a confirmed delivery (M-14-T03)

- `POST /deliveries/{note}/corrections` → `Actions\StateMachines\M14Delivery\T03CorrectDelivery`. Body: `lines[{line_id
  (delivery note line), quantity}]`, `destination` (`stock | reservation`, required when a ready line is corrected),
  `reason` (3..1000), `idempotency_key` (required), `lock_version` (the note's; stale → 409).
- **Append-only.** The note and its lines are never edited. A `delivery_corrections` row (reason, destination, actor,
  the «first delivery» date it saw) and `delivery_correction_lines` (per note line and, for ready goods, per reservation
  piece) take quantity back. `DeliveryProgress::deliveredByLine()` is net of corrections, so returns, cancellation,
  completion and closure all read the corrected base.
- **The responsible user chooses** where a ready quantity goes:
  - `reservation` — movement `correction_reserve` (on hand +q, reserved +q) on the reservation the note issued from;
    the reservation's `issued` falls, it is open again, the order owes the quantity and a `delivered` order goes back to
    `partially_delivered` (M-05 T08 with `delivery_correction` in its context). A later delivery uses the normal path.
  - `stock` — movement `correction_in` (on hand +q) and the reservation records the part as released; the quantity is
    free stock and no longer owed. **No money changes here**: if a price consequence exists it is settled through
    cancellation / refund (ADR-0015, ADR-0020), as for any other quantity no longer delivered.
  - Each movement is the exact counterpart of the issue it corrects (same reservation, location and item), newest
    reservation first, never more than that note issued from it minus earlier corrections. Made-to-order lines move no
    stock (FUL-10); their quantity is owed again.
- **Ceilings:** per note line, at most what it recorded minus earlier corrections; for ready lines also never below what
  accepted returns hold (`ReturnEligibility::heldByLine`). Accepted only while the order is open for delivery
  (ready `partially_delivered | delivered`, custom `in_progress | execution_finished`); never on a closed order.
- **Permissions:** **P-52 on the order** (as for recording) **and P-49 on every stock location the correction moves**
  (P-49 «adjust stock balance»: the correction changes on-hand outside a sale). A made-to-order correction needs P-52
  only. Defaults: owner and inventory officer hold both; managers, supervisors and executors hold P-52 only, so they
  can correct made-to-order deliveries but not ready stock. *To confirm with the product owner* (see report).
- **Audit and trail:** M-14 `T03` transition on the note (confirmed → confirmed, with lines and destination), audit
  `delivery.corrected` on the note and `stock.correction_returned` on each reservation, the stock movements with
  `source_type = delivery_correction`. Everything is reconstructible; nothing is deleted.
- **«First delivery» date:** unchanged. `DueCalendar` keeps the current rule — the `delivered_on` of the first
  confirmed note — even when that note is corrected (partly or to zero). A correction never moves an already derived
  due date. The correction stores `first_delivery_on` (the date the rule used at that moment) and dispatches
  `App\Delivery\DeliveryCorrected(orderId, correctionId)` after commit. **Open point:** payment schedules will become
  configurable; the future rule engine decides whether a correction re-derives «at delivery» dues, using that event and
  the stored date. No new due-date rule is hard-coded now.

## 2. Identity of staff screens (Q-03 → option a)

- Admin, staff and manager screens always use OpsOne's own logo, colours and fonts (`app.blade.php`,
  `data-surface="panel"`, OpsOne tokens). The facility's theme (colours and content) applies only to the facility-facing
  surfaces: the public site and the store (unchanged, `[data-surface='site']`) and, new here, the customer portal.
- Verified: no tenant colour reached the administration before this change (the draft and applied theme only produced
  site CSS). The portal did not carry the facility colours either; `Site\Theme\PortalBrand` now adds the **live**
  palette, scoped to `[data-brand='facility']`, colours only (the portal keeps the panel type), on `customer.*` routes.
  It reads the theme row without creating it, never the draft. `ThemeSettings::css()` gained a scope argument.
- The skipped acceptance test in `Scenario18ThemePreviewApplyTest` now asserts the decision: no brand colour, brand
  scope or font override on `/dashboard` and `/orders` for owner, manager and sales; the brand on `/portal`, the site and
  the store.

## 3. Several delivery remarks (M-14-T04)

- Verified what ADR-0032 built: `delivery_remarks` already allows any number of remarks per order (and per note), each
  resolved by `POST /delivery-remarks/{id}/resolve` with `resolution`, `resolved_by`, `resolved_at` (row lock,
  `lock_version`, a second resolve is 409). Gaps filled:
  - the closure blocker `remark_open` now carries `items` (id, note number, description) and the 422 lists one line
    per unresolved remark; the order card lists them under the blocker;
  - the order page lists **all** remarks (open first, then resolved) with who recorded it, the follow-up person, and
    who resolved it, when and how (the old list was capped at 20 and showed neither who nor when);
  - a read endpoint for other clients: `GET /orders/{order}/delivery-remarks` (P-52 on the order) →
    `{remarks: [{id, delivery_note_id, note, description, status, recorded_by, recorded_at, follow_up, resolution,
    resolved_by, resolved_at, lock_version}]}`; the create / resolve answers return the same row shape.
- **Stable contract** for the installation field app: the three routes, their names
  (`deliveries.remarks.index|store|resolve`), the payloads above and `App\Delivery\DeliveryRemarks::row()` are the API;
  extend by adding keys only. Documented in `docs/p7-inventory-api.md` «Delivery remarks».

## 4. Debt write-off at closure

- **New permission P-103** «Write off a customer's outstanding balance or reverse the write-off» (finance group, all
  order scopes, default role: owner only; the owner may grant it). It is not in matrix v1.1; added by this decision.
- `POST /orders/{order}/finance/write-offs` → `Actions\Finance\WriteOffDebt::handle()`: made-to-order orders only (ready
  orders carry no dues), only in a closure state (`execution_finished | cancelled`; otherwise 409), P-103 on the order,
  the finance head `lock_version`, a mandatory `reason`, an optional `file` (private attachment `write_off_proof`, viewed
  with P-37) and `amount_minor`, which **must equal** what is owed now (else 422 and nothing written).
- **Ledger (ADR-0013..0016 style):** `debt_write_offs` (gapless `WO-YYYY-000123`, kind `write_off | reversal`,
  `reverses_id` unique) and `debt_write_off_entries`. A write-off credits the `receivable` of every due by what it still
  lacks (negative entries) and debits `bad_debt` by the total; the journal sums to zero (checked before insert, append-only
  models refuse update/delete). It is not a payment: `money_journals` / `money_entries`, «collected», receipts and
  commissions are untouched.
- **Reversal only:** `POST /finance/write-offs/{id}/reverse` (P-103, reason) writes a `reversal` journal that negates
  the entries exactly, once. The debt is owed again; credit already on the order is then allocated oldest due first.
- **Effect everywhere «owed» is read:** closure blocker `money_owed`, `OpenDues` (finance work list, reminders, aging),
  the allocator (never allocates into a written-off part; trim keeps it), the order money card (`written_off_minor`
  per due and in the summary, remaining after write-off), the portal (no longer asked of the customer), and reports:
  metric `collection.written_off_minor` (net, never in collected or net received) and the collection report's month
  card. The schedule cannot change while a write-off stands (`finance.write_off.schedule_locked`): reverse it first.
- Audit: `finance.debt_written_off` and `finance.debt_write_off_reversed` on the order, with reason, amounts per due and
  the number.

## Consequences

- Migration `2027_04_01_100000_create_delivery_corrections_and_debt_write_offs` (`#[Expand]`, additive only).
- New stock movement kinds `correction_in`, `correction_reserve`; new audit actions; P-103.
- Still open: whether a correction should re-derive «at delivery» dues (future schedule engine), M-04-T08 (rework after
  closing), and the P-49 requirement for ready-goods corrections (confirm with the product owner).
