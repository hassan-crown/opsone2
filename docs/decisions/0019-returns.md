# ADR-0019: Ready-goods returns — eligibility, receipt, inspection and refund (P7d)

- Status: accepted (2026-10-28). Includes a change to P6 (ready-order money on the manual path), approved by the manager.
- Scope: domain 06 (SRS-FUL-06, FUL-11), INT-02 / INT-03 / INT-05 / INT-06, process P04, machine **M-15** (T00 request,
  T05 accept, T06 reject, T01 receive, T07 further receipt, T02 inspect, T03 withdraw, T04 correct), integration scenarios
  7 and 8 and the return side of scenario 4. Permissions (all over the **order**): P-53 or P-42 request, P-102 decide,
  P-53 receive and inspect (also on the receiving location), P-53 + P-49 correct, P-42 refund request, P-43 refund
  execution (M-12), P-37 amounts, P-02 return window.
- Builds on: ADR-0018 (delivered base `DeliveryProgress::deliveredByLine`), ADR-0017 (stock ledger, order lock),
  ADR-0015 (M-12 refunds and `RefundLimits`), ADR-0016 (commission hook and `SettlementHolds`), ADR-0007 (outbox).
- Not in this phase: customer-portal requests (P8; `source` is `staff` now), replacement orders, custom-order repair
  (a correction task in P5, never a return), M-14-T03 delivery correction.

## Decision

- **Records**: `order_returns` (number `RT-YYYY-000123`, status, reason, window in force, decision, value and expected
  settlement, `refund_id`, `lock_version`), `order_return_lines` (per order line: requested, **accepted** = held
  eligibility, received, unit price snapshot) and `order_return_parts` (one physical receipt at a location, inspected
  once: good / damaged / rejected). `return_settings.window_days` (default 14, P-02).
- **Number at the request** (`GaplessSequence 'order_return'`, 6 digits, per year): a request is a real record that may
  be rejected or withdrawn but keeps its number, so the sequence has no holes; a refused request rolls back with its
  number.
- **Eligibility (INT-03)**: eligible = delivered (confirmed notes) − Σ accepted of returns in `accepted | received |
  inspected`. Received is part of accepted, so nothing is subtracted twice. A request holds nothing; acceptance (T05)
  re-reads eligibility **under the order row lock** (the same lock as delivery and cancellation) and refuses
  `over_delivered` (409) — two acceptances of a delivered 2 never hold 3 (scenario 7). Withdrawal (T03) releases only the
  accepted quantity not yet received; rejection and withdrawal before receipt free everything.
- **Window**: shown to the decider as an indicator (`overdue`), never a refusal. The days in force are kept on the
  return at the request (the delivery note is owned by P7c), so a later change of the setting never re-evaluates it.
- **Receipt and inspection**: T01 (first) / T07 (further parts) record actual quantities ≤ what is still expected at a
  location that may serve the order and that the receiver's P-53 covers. Nothing becomes available before inspection
  (FUL-06). T02 sets the outcome of each part once (good + damaged + rejected = received). Receipt and inspection may be
  one step (`inspect: true`): both transitions are written and stock moves once. State after a receipt is derived from
  quantities: `received` while anything is expected or uninspected, `inspected` when everything accepted was received
  and inspected.
- **Stock movements** (append-only through `StockLedger`, key `return:{part}:{what}`, document number = RT): good →
  `return_in` (+on hand, available at once); damaged → `return_damaged`, a custody row with **no balance effect** so the
  journal shows where damaged goods sit without ever making them available; rejected → no movement (handed back).
  T04 correction posts a counter movement: damaged → good `return_in`; good → damaged `return_reclass` (−on hand, only
  if still available) plus a custody row. Nothing is edited or deleted.
- **Money (INT-05)**: acceptance fixes the value of the accepted goods (their share of the line total as sold, half-up
  once) and the expected settlement (≤ value, default = value). The inspection never decides the money: damaged goods are
  not refused a refund by classification. The refund goes through the P6 flow: `RequestReturnRefund` (P-42) calls
  `Returns\Contracts\ReturnRefunds` (bound to `M12ReturnRefunds` → `M12Refund\T00RequestRefund`), which **reserves** the
  amount from the payment's refundable limit under the order lock. One live refund per return (`refund_exists`), amount
  ≤ settlement (`above_settlement`) and ≤ the payment's refundable money (`not_refundable`); a failed or cancelled refund
  may be asked again. Execution stays M-12-T02 by P-43 with proof (`RF-…`).
- **Money on ready orders — a change to P6 (manager decision, option A)**: P6 refused every money write on ready
  orders (`FinanceLock` `ready_order`). A return refund needs money on the order, so `FinanceLock::READY_PATHS` admits
  ready orders on the **manual path only**: a staff-recorded payment M-11-T01 (P-39, credit on the order — no agreement
  or dues) and staff refunds M-12 T00 request (P-42), T01 start / T02 confirm / T04 failure / T07 retry (P-43), T06
  cancel (P-42). Online payments (gateway M-11-T00/T02), customer proofs (M-10), provider refund results (M-12-T03/T05),
  corrections, credit transfers, schedules, disputes, documents and commissions still refuse ready orders. Regression
  tests: `ReadyOrderMoneyTest` (grants, reserved limit, idempotency, online still refused) and `CustomOrderMoneyTest`
  (made-to-order finance unchanged). **P8 revisits ready-order money with the store checkout** (online payment,
  provider refunds).
- **Commission (INT-06)**: acceptance calls the 6d hook `CommissionAccrual::recompute($order, 'return:{id}', …)` (a
  no-op while ready orders carry no sale) and `ReturnSettlementHolds` decorates `SettlementHolds`: an order with a return
  undecided / expected / under inspection, or inspected with a settlement and no refund asked, is held (`settlement`).
- **Settlement follow-up**: consumed through `Returns\Contracts\SettlementFollowUp` (`open` on acceptance with money and
  on inspection, `close` = re-read after a refund request, a partial release or a withdrawal). Bound at the P7 merge to
  `Settlement\ReturnSettlementFollowUp`: one `return` item per return (source `return:{id}`, target = settlement) on the
  order's single follow-up (ADR-0020), followed from the return — done when its M-12 refund is executed, dropped when
  the return ends without money, target lowered on a partial release; resolved otherwise by P-42. Refunds a return asks
  never cover a cancellation's `refund` item, and the cancellation review leaves them out of collected and pending
  money (they pay back delivered goods the cancellation keeps counting), so the two never claim the same halala.
- **Concurrency and idempotency**: every write takes an idempotency key (replay = first result, other payload = 422),
  locks the order row then the return row, checks `lock_version` (409 `stale_version`) and the source state (409
  `not_from_{status}`); refusals leave no row, movement, number, transition, audit, notification or key.
- **Audit, transitions, notifications**: each transition writes `state_transitions` (M-15) and an audit event (area
  `returns`). Outbox notifications after commit: `return.requested` → P-102 holders, `return.accepted` → P-53,
  `return.inspected` (money still open) → P-42; links re-check the permission on the live return.
- **Amounts hidden without P-37**: `value_minor`, `settlement_minor`, line values, unit prices, refund amount and the
  payments list are omitted (keys absent, never 0).
- **Screens**: ReturnList (`returns/index`: eligible delivered orders, returns, window setting for P-02), ReturnNew
  (`returns/form`), ReturnPage (`returns/show`: decision, receipt + inspection, parts, correction, settlement and refund).

## Consequences

- New movement kinds `return_in`, `return_damaged`, `return_reclass` appear in the stock journal and its filter.
- The cancellation session's CancelReview can read open returns (`ReturnStatus::isOpen`, `ReturnSettlementHolds::open`).
- Merge hot spots: `StateMachine` (M15), `AuditAction` (+area `returns`), `MovementKind`, `NotificationCatalogue`,
  `bootstrap/providers.php`, `routes/web.php`, `HandleInertiaRequests` orgNav + `navigation.ts` + `global.d.ts`, lang tail
  block `returns.*` (+ `stock.kind.return_*`, `audit.*`), `FinanceLock`.
