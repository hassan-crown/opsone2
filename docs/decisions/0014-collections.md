# ADR-0014: Order money, dues, collections, receipts and allocation (P6b)

- Status: accepted (2026-10-28)
- Scope: domain 05 (SRS-FIN-01..07, FIN-11, INT-04, INT-07), machines M-10 (customer transfer proof) and M-11
  (collection transaction), permissions P-37..P-40 (+P-41/P-43 reserved for 6c), P-60 for the report.
- Builds on: ADR-0011 (orders, gapless numbers, idempotency, `lock_version`), ADR-0012 (engine results, INT-07),
  ADR-0013 (Money, agreement, `AgreementApplied`, `CollectedAmounts`).
- Product decisions: Q-62 cash proof is an owner setting (decided 2026-10-28, see «Recording»); Q-41 receipt numbers per facility and year `RC-2026-000123`; Q-42 refunds are recorded manually
  in 6c (nothing here); FIN-07 online payment is an optional P8 integration — only an interface and the
  verified-webhook path exist now.

## Decision

### One ledger, append-only

- **Payment** (`payments`, M-11): one row per real money movement from the customer — amount (positive halalas),
  method (`transfer|cash|card|cheque|online`), date, reference, origin (`staff|customer_proof|gateway`), status. A
  trusted reference (`reference_key` = method + normalized reference) is **unique**: the same bank transfer or provider
  transaction can never become two payments, whichever channel reports it first (INT-04).
- **Receipt** (`receipts`, «سند قبض»): exactly one per confirmed payment (`payment_id` unique), number
  `RC-YYYY-000123` from `GaplessSequence('receipt', year)` inside the confirming transaction (a rollback gives the
  number back; concurrent confirmations queue on the counter row). Immutable. It is not a tax invoice.
- **Money journal** (`money_journals` + `money_entries`): every effect of a payment is a journal with signed entries
  `(payment, order, due|null, amount)`; rows are never updated or deleted (model guards). Kinds: `collection`
  (+amount as order credit), `allocation` (credit → due), `deallocation` (due → credit), `transfer` (credit of order
  A → credit of order B of the same customer), `reversal` (the negation of one earlier journal, linked by
  `reverses_journal_id`, unique — a journal is reversed at most once). Every journal except `collection` (and its
  reversal) sums to zero.
- Derived figures, never stored as truth:
  - net collected on an order = Σ entries of the order (this is `CollectedAmounts::collectedFor`, bound now);
  - allocated to a due = Σ entries with that due; order credit («مقدم غير مخصص») = Σ entries of the order without a due;
  - remaining on the order = agreed value − net collected (negative → shown as excess needing allocation or refund,
    never as a negative debt);
  - due now = Σ (amount − allocated) of dues whose date or event has arrived.

### Dues (installments) and the schedule

- `order_dues`: name, `reference` (the same key a P5 `payment_check` uses: `first`, `final`, …), basis
  (`amount` fixed halalas, `percent` of the agreed value in basis points, `rest` = what the other dues leave), event
  (`agreement` | `payment_stage` = when the flow stage holding the `payment_check` with this reference starts |
  `date`), position. The amount is computed from the active agreement; percentages half-up through `Money`; the last
  `rest` due absorbs the rounding, so the schedule sums exactly to the agreement when complete (unscheduled part shown
  otherwise; a schedule above the agreement is refused).
- The schedule is created when the agreement is fixed — the approval (`FlowAction_appr`: «after approval, fixing the
  quote creates the order's payment schedule») — from the facility default template (50% at the agreement, the rest
  when the final payment's stage starts). It is synchronised from `OrderAgreement` (never from event numbers) by the
  `AgreementApplied` listener and, idempotently, at the start of every finance write and read (`OrderSchedule::sync`),
  so a lost event heals itself. An applied change recomputes amounts; credits are re-allocated.
- `FinSchedule` (P-38) replaces the schedule: refused when the sum exceeds the agreement, or when a due would fall
  below what is already allocated to it (`due_has_allocations`) — a paid due never changes silently.
- Derived status per due: `upcoming`, `due`, `overdue` (date passed and something left), `partially_paid`, `paid`.

### Allocation

- Automatic, oldest due first (schedule position), exact halalas, never above a due's remaining amount; what is left
  stays as order credit — an overpayment is held, not lost, and never raises the agreement. A deposit before the
  agreement stays credit and is allocated when the schedule appears, without a new receipt (FIN-03, scenario 10).
- P-38 may redistribute a payment's share on the order over the dues (`Reallocate`) and transfer credit to another
  order of the same customer (`TransferCredit`, `FinAllocate`); recording may split a payment over two orders of the
  same customer (FIN-04: 7,000 + 3,000 of one 10,000 — sum never above the payment).

### Recording and confirming (M-11, M-10)

- Staff with P-39 record a received payment: it is confirmed at once (M-11-T00 + T01), one receipt, allocation,
  no second approval (FIN-04). A reference already used is refused (`duplicate_reference`); a pending customer proof
  with the same reference is refused with `matching_proof` (confirm it from the proof instead); a payment of the same
  customer and amount within 3 days without a matching reference is only a warning (`similar_payment`) that the
  person acknowledges — a real payment is never blocked for looking alike.
- **Cash proof (product owner, decided 2026-10-28; Q-62):** «proof file required for cash collections» is a facility
  finance setting (`finance_settings.cash_proof_required`, default **required** — the behaviour above) that only the
  facility owner changes (`UpdateCashProofPolicy`, `SettingsSales`, audited `finance_settings.cash_proof_updated`).
  When off, a **cash** collection may be recorded without a file, but its collector (the recording member,
  `recorded_by`) and a note with the reason stay mandatory (422 `note`); a transfer, card or cheque always requires
  its proof (422 `evidence`). The receipt, allocation and conditions are unchanged.
- A customer proof (`payment_proofs`, M-10, P-68 from the portal in P8 — service `CustomerProofs`) is never money.
  P-40 confirms the amount actually received (M-10-T01): if a payment with the same trusted reference already exists
  the proof is **linked** to it (no new payment, receipt or allocation — INT-04); otherwise one payment + one receipt
  are created and linked. P-40 may ask for a correction with a visible reason (T02); the customer resubmits (T03, the
  previous proof kept); the customer or P-40 may cancel an unconfirmed proof (T04). The file is a private attachment.
- Online payment (FIN-07, P8): `App\Finance\Gateway\PaymentGateway` (start a payment, verify a webhook). A payment
  becomes confirmed **only** through `GatewayPayments::apply(VerifiedGatewayEvent)`, whose event can be built only by
  a gateway's signature verification; the browser return page never confirms. Pending/unresolved/failed/cancelled
  follow M-11-T02..T06; a repeated event returns the known result (T08). No gateway is bound now (`NoGateway`).

### Every money write

Idempotency key (`Support\Idempotency`, scope `finance.*`) + `lock_version` of the order's finance head
(`order_finances`) + `LockingTransaction` + row locks: the order rows (several in id order), then the payment/proof
row. Each writes `state_transitions` (M-10/M-11) and `audit_events` in the same transaction. Errors follow ADR-0013:
403, 422, 409 `{condition}`, 409 `stale_version`.

### Stage collection condition (INT-07)

After every ledger change the order's `payment_check` actions are re-evaluated against the net collected of the
order: `amount` → collected ≥ value; `percent` → collected ≥ `OrderAgreement::share()`; `remaining` → collected ≥
agreed value (no agreement → not met). A met condition is reported through `ActionResults::verifyFromSource()`
(`source = order_collection:{payment}`) inside the same transaction; the collection itself is never consumed, so
50% then 100% compare the same cumulative amount. A customer proof under review never satisfies it (FIN-02). A
later reversal never un-completes a stage: the shortfall is listed for the authorized person (`shortfalls`).

### Visibility, notifications, reminders

- Amounts only with P-37 over the order; the finance tab needs P-16; executors (task fields only, P-24) never get a
  finance payload, and task payloads carry no amount.
- Outbox: a confirmed receipt notifies the customer (SMS/mail through `Outbox`, key `receipt:{id}`) and the order
  assignee in-app. `finance:remind` (hourly on the scheduler) sends «due soon» (due within 2 days) and «overdue»
  (weekly) reminders to the customer only inside the facility reminder hours (`WorkSettings::canSendReminderAt`),
  re-checking the amount at send time and excluding amounts held by an open dispute (`DisputedAmounts`, 0 until 6c).

## Consequences

- 6c (corrections, refunds, disputes, external invoices) builds on `MoneyLedger::reverse()` and the journals: a
  correction reverses the payment's journals (M-11-T07) and recomputes conditions; a refund reverses the latest
  allocations of the original payment first. Contract in `docs/p6-finance-api.md`.
- 6d reads allocations per payment and the agreement's `products_minor` for commission.
- Derived figures are summed on read; a finance list is bounded (500 orders with open dues) — revisit with a cached
  snapshot if a facility outgrows it.
- Not here: refunds, corrections UI, disputes, external invoices (6c), commissions (6d), the customer portal screens
  and the gateway implementation (P8).
