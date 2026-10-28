# ADR-0015: Refunds, corrections, financial disputes and external accounting documents (P6c)

- Status: accepted (2026-10-28)
- Scope: domain 05 (SRS-FIN-08, FIN-09, FIN-10, FIN-13, the refund parts of FIN-05/FIN-11/FIN-12, INT-05, INT-07),
  machines M-12 (refund), M-11-T07 (correction), M-39 (financial dispute) and M-40 (external accounting document),
  permissions P-41, P-42, P-43, P-44, P-85 (+ P-37 for amounts). Integration scenarios 9, 11 and 12 (refund parts).
- Builds on: ADR-0011 (gapless numbers, idempotency, `lock_version`), ADR-0013 (Money, agreement), ADR-0014 (the
  append-only money journal, `MoneyLedger::reverse()`, `FinanceLock`, `CollectionConditions`, `DisputedAmounts`).
- Product decisions (defaults confirmed for this phase): Q-42 refunds are paid outside the system and recorded
  manually with proof in v1 (provider refunds come with the payment integration in P8); Q-38 a financial dispute is a
  new machine M-39 open → awaiting external party → resolved, linked to an order and a money movement; Q-39 an external
  accounting document is a new machine M-40 requested → attached awaiting review → accepted / needs correction; Q-41
  refund vouchers are numbered gapless per facility and year (`RF-2026-000123`) on the existing locked counter.

## Decision

### Refund (M-12)

- `refunds`: one refund of part of **one payment's** money on **one order** — amount, refund method, reason, status,
  attempt, bank/provider reference, failure or withdrawal reason, voucher number, the refund journal. Amount, payment
  and order never change; nothing is deleted.
- **Refundable limit** (`RefundLimits`) = what the payment still has on the order (`Σ MoneyLedger::placesOf`, already
  net of executed refunds, transfers and reversals) − every refund that holds a reservation (`requested`,
  `in_progress`, `unresolved`). The limit is read **under the order row lock** at the request (T00), at sending (T01)
  and at a retry (T07): two concurrent requests queue on the order row, so together they never exceed it (scenario 9:
  300 reserved → 800 of 1,000 refused; unknown keeps the reservation; a final failure releases it). Credit reserved by
  a refund cannot be moved to another order (`TransferCredit` checks `RefundLimits::freeCredit`).
- Transitions (`RefundStatus::transitions()`): T00 request (P-42) · T01 sent (P-43) · T02 confirmed with external
  proof (P-43; from `requested` it records T01 first — the usual manual payout) · T03 unknown result and T05 trusted
  success (provider service `RefundProviderResults`, P8; T05 must carry the same reference) · T04 proven failure
  (provider, or P-43 with a reason in v1) · T06 withdrawal of a request never sent (P-42) · T07 retry after failure
  (P-43, limit re-checked). Each grant acts alone — the request and the execution need not be one person, and the
  executor's P-43 is the approval of the payout (P05: «كل فعل يتحقق من إذن صاحبه»).
- **Execution** (`RefundPosting`, the only place refund money leaves): the payment's credit on the order is used
  first; if it is not enough, the payment's **latest allocations** go back to credit (newest due first, one
  `deallocation` journal); then one `refund` journal takes the amount off the order's credit; the voucher number is
  taken in the same transaction (a rollback gives it back). Other credit is re-allocated, the stage checks are
  re-evaluated (a completed stage is never un-completed — `CollectionConditions::shortfalls()` lists it), the finance
  head is touched and `RefundExecuted` is dispatched **after commit** for 6d (idempotent on `refundId`).
- The request alone never reduces the net collected (FIN-09); an executed refund reduces it once (INT-05).

### Ledger: why a `refund` journal kind

ADR-0014 makes reversal the only *correction* primitive: no row is edited or deleted, and a wrong journal is cancelled
by its exact negation. A refund is not the correction of a wrong row — it is real money leaving — and a partial refund
cannot be the exact negation of any earlier journal (a collection is reversed at most once). It is therefore written as
a new signed journal kind `refund` (entries only on order credit, sum < 0), checked by the same guards (no place of the
payment goes negative). Moves to credit before it are ordinary `deallocation` journals. A refund recorded in error would
be undone with `reverse()` like any journal (not exposed in v1). The append-only invariant is unchanged.

### Correction (M-11-T07, FIN-08)

- `T07CorrectPayment` (P-41, FinCorrect): `void` (recorded by mistake) or `amend` (right amount/date/method/reference).
  Every open journal of the payment is reversed (`MoneyLedger::reversePayment`, newest first), the payment becomes
  `corrected`, its receipt stays as issued and a **correction voucher** `CR-YYYY-000123` (`payment_corrections`, written
  once with before/after and the reversed journal ids) is added. An amendment re-enters the right figures as a new
  confirmed payment with its own receipt — the reversal + re-entry pair. The re-entry keeps the corrected payment's
  trusted reference out of the unique key (the original still holds it, so INT-04 stays protected).
- The P-41 grant with a mandatory reason is the approval (no second person imposed, as for P-39). Refused without
  effect: `payment_has_refunds` (a refund holds or took part of it — handle the refund first), `split_payment` (an
  amendment of money spread over several orders: void and record again), `nothing_changed`, `not_from_corrected`.
- A correction is never a bank refund; allocation and stage checks are recomputed, completed stages stay.

### Financial dispute (M-39, FIN-13)

- `financial_disputes`: order, the movement (a payment of the order, a refund, or a due), subject, reason, amount
  (above the movement only with an explanation), responsible member (active, P-16 on the order), status, external
  party, outcome, resolution and its proof file. T00 open, T01 awaiting external party, T02 answered (open again), T03
  resolved with proof — all P-85. A state change never moves money: an upheld dispute is followed by a refund or a
  correction from its own action.
- `OpenDisputeAmounts` binds `DisputedAmounts`: the disputed part of a due is excluded from every collection reminder
  while the dispute is open (FIN-11), re-read at send time; a resolution sends nothing at once.
- Open disputes are counted on the order (`settlement.open_disputes`) for the order's full closure readiness (P7).

### External accounting document (M-40, FIN-10, BR-20)

- `external_documents`: order, kind (tax / simplified invoice, credit / debit note — the type the accounting system
  gave it), event (payment, refund, delivery, whole order, with the payment or refund), source system, number, date,
  amount, currency (SAR), file(s), review status. The number is unique per source (`source_key`, `number_key`
  normalized; unique index) — `duplicate_number`. T00 requested (P-44), T01 attached (to a request or new), T02
  accepted, T03 needs correction (reason kept), T04 attached again (earlier files kept). OpsOne never issues a tax
  invoice; linking or accepting is an internal review, not a compliance claim. For a tax invoice the customer's VAT
  number and CR from their profile are shown with what is missing (a warning, never a block).

### Every write

Idempotency key (`FinanceWrite`, scopes `finance.refund.*`, `finance.payment.correct`, `finance.dispute.*`,
`finance.document.*`) + the version the screen read (the order finance head for creations, the record's
`lock_version` for its transitions; 409 `stale_version`) + `LockingTransaction` + row locks: the order(s) first (the
same row as every money write), the finance head, then the record. `state_transitions` (M-11, M-12, M-39, M-40) and
`audit_events` (area `finance`) in the same transaction. Errors: 403 Gate, 422 validation, 409 `{condition}`.

### Visibility and screens

`SettlementPresenter::forOrder()` (caller checks P-16): without P-37 every `*_minor` key and the money files are
absent (render «hidden»); document files follow P-44. Screens: the settlement card on `FinOrder` with the dialogs
`FinRefund`, `FinRefundExec`, `FinCorrect`, `FinDispute`, `FinDisputeResolve`, `FinInvoiceLink`; `FinInvoices`
(`GET finance/invoices`, P-44); FinHome lists refunds not yet executed and open disputes. Routes in
`routes/finance-refunds.php`.

## Consequences

- 6d listens to `RefundExecuted` (or reads the ledger) for the negative commission difference; refunds and open
  disputes hold the affected part of commission payout (6d).
- Not here: provider refunds and their webhook (P8 — `RefundProviderResults` is the entry point), automatic document
  requests from facility billing events (a P-44 holder requests them manually in v1), settlement follow-up of a
  cancelled order and the order's full closure readiness (P7, reads `open_disputes` and refunds not executed),
  reversing an executed refund recorded in error (would use `reverse()`).
