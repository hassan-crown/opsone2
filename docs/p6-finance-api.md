# P6 finance — service API (6a: money foundation and agreement · 6b: collections)

Decisions: [ADR-0013](decisions/0013-money-and-agreement.md). 6b/6c/6d extend this file with their own sections.

## Conventions (every P6 write)

- Money is `App\Money\Money` (integer halalas). Never a float; never `0` for an unknown amount (`null`).
- Rounding only through `App\Money\Rounding` / `Money` (half-up per line, Q-34 a). Splitting an amount over parts:
  `Money::allocate(list<int> $weights)` — the parts always sum to the amount.
- VAT per line: `FinanceSettings::current()->vat()->line(Money $amount)` → `VatBreakdown {net, vat, gross}`.
- Money-affecting writes go through `App\Agreements\AgreementWrite::run($scope, $actor, $idempotencyKey, $payload,
  $work)` (or `Support\Idempotency` directly) and check the `lock_version` the screen read
  (`AgreementLock::checkVersion`). Lock the order row first (`AgreementLock::order()` / `WorkflowLock::order()`).
- Errors: 403 Gate, 422 validation (`idempotency_key` reused with another payload too), 409 `{condition}`
  (`InvalidTransition`) or `{code: "stale_version", lock_version}` (`StaleRecord`).

## For 6b/6c/6d — reading the agreement

| Call | Returns | Notes |
|---|---|---|
| `OrderAgreement::current(Order)` | `?Agreement` | Active version: `version`, `content` (priced lines), `net/vat/total/discount/products_minor`, `previous_total_minor`, `difference_minor`, `settlement_needed_minor`, `approved_at`. |
| `OrderAgreement::value(Order)` | `?Money` | Agreed value (VAT-inclusive total). `null` before the first approval. |
| `OrderAgreement::productsValue(Order)` | `?Money` | Commission-eligible product lines after discount (as entered: gross when prices are inclusive, net otherwise). Scenario 10: 1,000 of 1,200. |
| `OrderAgreement::share(Order, int $bp)` | `?Money` | `value × bp / 10000`, half-up. INT-07 `payment_check` basis `percent` (50% then 100% of the same agreement). |
| `OrderAgreement::history(Order)` | `Collection<Agreement>` | All versions, oldest first; superseded ones are immutable proof. |
| `OrderAgreement::pendingChange(Order)` | `?AgreementChange` | Open change; its `affected` work is held while presented. |
| event `App\Agreements\AgreementApplied` | `orderId, agreementId, version, previousTotalMinor, totalMinor, differenceMinor, settlementNeededMinor` | Dispatched **after commit** on every activation (first approval and applied change). Listeners must be idempotent on `agreementId`; recompute dues from `OrderAgreement`, never from the event numbers alone. |
| bind `App\Agreements\CollectedAmounts` | `collectedFor(Order): Money` | **Bound by 6b** to `LedgerCollectedAmounts` (the order's net collected; 6c refunds reduce it through reversals). Read inside the activation transaction for `settlement_needed_minor` (AGR-06: 20,000 → 18,000 with 19,000 collected → 1,000 to settle). The default `NoCollections` returns 0. |

Nothing in 6a creates a due, a collection or a refund. A lower value only records `settlement_needed_minor`.

## Agreement services (6a)

All Actions authorize on the order through the Gate, lock it, check the machine and write `state_transitions` +
`audit_events` in one transaction. `$key` = idempotency key, `$lockVersion` = version read (null for system callers).

| Action | Permission | Notes |
|---|---|---|
| `M08Document\T00CreateQuote::handle($user, $order, $key)` | P-29 | Draft from the order lines; refused `agreement_exists`, `quote_open`, `ready_order`, `order_closed`. |
| `M08Document\UpdateDocumentDraft::handle($user, $doc, $data, $lockVersion, $key)` | prepare permission of the kind (P-29/P-33/P-32) | Quote: `QuoteInput::rules()` (`lines[{order_line_id?, description, quantity "1.5", unit?, unit_price "1250.50"?, commissionable?}]`, `discount {type none\|percent\|amount, value "12.5"}`, `terms`, `execution_days`). Supersedes discount decisions on other content. |
| `M08Document\T00UploadDesign::handle($user, $order, UploadedFile, $description, $lineIds, ?$replaces, $key)` | P-33 on the design (order assignee or the holder of an open «prepare design» task with an `assigned` grant) | Private attachment, purpose `design`. |
| `M08Document\T00PrepareContract::handle($user, $order, ?UploadedFile, ?$terms, $key)` | P-32 | From the active agreement (party data copied; `missing[]` listed, never blocking) or an external file. Needs `contracts_enabled`. |
| `M08Document\ReviseDocument::handle($user, $doc, $key)` | prepare permission | Draft replacement of a sent quote/contract (`replaces_id`). |
| `M08Document\T01SendDocument::handle($user, $doc, ?$validityDays, $lockVersion, $key)` | P-30 | M-08-T01 (+T04 on the replaced one). Conditions: `content_incomplete`, `discount_needs_decision`, `no_recipient`, `agreement_exists`. Verifies P5 `prepare_document`. |
| `M08Document\T02RecordExternalApproval::handle($user, $doc, {channel, decided_on, note?, content_hash}, UploadedFile $evidence, $lockVersion, $key)` | P-34 | M-08-T02. `content_changed`, `expired`, `agreement_exists`, `already_approved`. Quote → agreement v1. Verifies P5 `customer_approval`. |
| `M08Document\T05ExpireDocuments::handle()` | time service | `agreements:expire-documents`, daily 00:10. |
| `M08Document\T06ExtendDocument::handle($user, $doc, $days, $lockVersion)` | P-30 | Same content, new period. |
| `M08Document\T08CancelDocument::handle($user, $doc, $reason, $lockVersion)` | P-30 | Draft / awaiting / changes requested only. |
| `M26Decision\T00RequestDiscount::handle($user, $docOrChange, $reason, $lockVersion, $key)` | P-29 (quote) / P-36 (change) | Only when above the requester's own limit (`within_limit` otherwise). |
| `M26Decision\T01ApproveDiscount::handle($user, $decision, true, ?$reason, $lockVersion, $key)` / `T02RejectDiscount::handle($user, $decision, $reason, …)` | P-31 over the order | `own_request`, `above_approval_limit`, `content_changed`. Approval does not send. |
| `M26Decision\T03CancelDiscount::handle($user, $decision, $lockVersion)` | the requester | |
| `M09Change\T00CreateChange::handle($user, $order, $description, $key)` | P-36 | `no_agreement`, `change_open`. |
| `M09Change\UpdateChangeDraft::handle($user, $change, $data, $lockVersion, $key)` | P-36 | `QuoteInput` fields + `description`, `affected` (action keys of this order), `delay_days`. |
| `M09Change\T01PresentChange::handle($user, $change, $lockVersion, $key)` | P-36 | T01 from draft, T03 after a revision request. Holds the affected work. `no_change`, `discount_needs_decision`. |
| `M09Change\T02RequestChangeRevision::handle($user, $change, $reason, $lockVersion)` | P-36 | Agreement and hold unchanged. |
| `M09Change\T04ApplyChange::handle($user, $change, {channel, decided_on, note?, content_hash}, UploadedFile, $lockVersion, $key)` | P-36 **and** P-34 | New version once, hold released, settlement recorded. `content_changed`, `agreement_changed`, `already_applied`. |
| `M09Change\T05WithdrawChange::handle($user, $change, $reason, $lockVersion)` | P-36 | Proposal only; releases the hold. |
| `CustomerDecisions::approveDocument / requestDocumentChanges / approveChange / requestChangeRevision(CustomerActor, …, $contentHash[, $note])` | P-67 (customer portal, P8) | The portal authenticates the customer and checks access first; this re-checks the order's customer. Records time and mobile (Q-40 a). |
| `UpdateFinanceSettings::handle($user, $data, $lockVersion)` | P-02 | `vat_rate_bp` 0..5000, `prices_include_vat`, `quote_validity_days` 1..365, `contracts_enabled`. |
| `UpdateDiscountLimits::handle($user, {limits: [{role_template_id, custom_bp, ready_bp, approval_bp}]})` | the owner | Per role template; the owner's role has none. |
| `AgreementPresenter::forOrder($order, $viewer)` | caller checks P-16 | The screen payload below. `amounts_visible` = P-37 or P-29 over the order. |

## HTTP (screens)

Routes in `src/routes/agreements.php`, behind `auth` + `verified`, writes throttled by `records-create`. JSON writes
answer with `{agreement: Payload}` (+ `document_id` / `change_id` / `decision_id` on creation, 201). Envelope fields:
`idempotency_key` (or `Idempotency-Key` header; required on money-affecting writes) and `lock_version`.

| Method & path | Name | Screen |
|---|---|---|
| `GET orders/{order}/agreement` | `orders.agreement` | `AgrOrder` (+ `AgrEmpty`, `AgrMobile`, `AgrSupervisor`, `AgrEnglish`, `AgrDark`, `AgrChangePending`) — Inertia `orders/agreement`; JSON with `Accept: application/json` |
| `POST orders/{order}/agreement/quotes` | `orders.agreement.quotes.store` | `AgrQuote` (create) |
| `PUT agreement/documents/{document}` | `agreement.documents.update` | `AgrQuote`, `AgrQuoteApproved` |
| `POST agreement/documents/{document}/discount-requests` | `agreement.documents.discount-requests` | `AgrQuote` → `AgrDiscountDecision` |
| `POST agreement/documents/{document}/send` | `agreement.documents.send` | `AgrSend` |
| `POST agreement/documents/{document}/external-approval` (multipart) | `agreement.documents.external-approval` | `AgrExternal` |
| `POST agreement/documents/{document}/revise` · `/extend` · `/cancel` | … | `AgrDocument` |
| `POST orders/{order}/agreement/designs` (multipart) | `orders.agreement.designs.store` | `AgrDesignUpload` |
| `POST orders/{order}/agreement/contracts` (multipart, file optional) | `orders.agreement.contracts.store` | `AgrContract` |
| `POST orders/{order}/agreement/changes` · `PUT agreement/changes/{change}` | … | `AgrChange` |
| `POST agreement/changes/{change}/present` · `/revision` · `/withdraw` · `/discount-requests` | … | `AgrChange`, `AgrChangePending` |
| `POST agreement/changes/{change}/apply` (multipart) | `agreement.changes.apply` | `AgrExternalChange` |
| `GET agreement/decisions/{decision}` | `agreement.decisions.show` | `AgrDiscountDecision`, `AgrDecisionMobile` (requester or P-31) |
| `POST agreement/decisions/{decision}/approve` · `/reject` · `/cancel` | … | idem |
| `GET/PUT settings/sales`, `PUT settings/sales/limits` | `settings.sales*` | `SettingsSales` (VAT, validity, contracts, discount limits) |

Payload (`AgreementPresenter::forOrder`): `amounts_visible, vat{rate_bp, inclusive}, contracts_enabled,
agreement{id, version, approved_at, source{type, number}, decision{…, evidence[]}, content, versions[], *_minor},
documents[{id, kind, number, status, replaces, valid_until, sent_at, sent_by, decided_at, content, content_hash,
lock_version, files[], decision, discount?, can{…}}], change{…, affected[{key, name, held}], can{…}}, history[],
can{create_quote, upload_design, prepare_contract, create_change}`. Without amounts every `*_minor`, `discount`,
`totals` and `presentations` key is absent — render «hidden», never 0.

## 6b — order money, dues, collections and receipts

Decisions: [ADR-0014](decisions/0014-collections.md). Code: `app/Finance`, `app/Models/Finance`,
`app/Actions/StateMachines/M10Proof|M11Payment`, `app/Actions/Finance`, `routes/finance.php`.

### Reading money (6c, 6d, reports)

| Call | Returns | Notes |
|---|---|---|
| `MoneyLedger::collected(int $orderId)` | `Money` | Net collected on the order (= `CollectedAmounts`). A proof under review is never included. |
| `MoneyLedger::credit(int $orderId)` | `Money` | Money on the order that no due holds (deposit before the agreement, overpayment). |
| `MoneyLedger::creditByPayment(int $orderId)` | `array<paymentId, int>` | Oldest payment first, positive only. |
| `MoneyLedger::allocatedByDue(int $orderId)` | `array<dueId, int>` | |
| `MoneyLedger::placesOf(int $paymentId, int $orderId)` | `array<dueId\|0, int>` | Where one payment's money sits on an order (0 = credit). 6c: refundable base per payment. |
| `MoneyLedger::sharesOf(int $paymentId)` | `array<orderId, int>` | A payment's share per order (split / transfers). 6d: allocations per payment for commission. |
| `Allocator::openDues(int $orderId)` | `Collection<OrderDue>` | Active schedule, oldest first (`position`). |
| `DueCalendar::dates(Order, $dues)` / `::status(amount, allocated, dueOn)` | | Due date from the event (agreement / payment stage start / date) and the derived `DueStatus`. |
| `CollectionConditions::overview(Order)` / `::shortfalls(Order)` | | INT-07 checks with their threshold; checks verified earlier that the ledger no longer covers. |
| `FinancePresenter::forOrder(Order, User)` | array | Screen payload (below). Caller checks P-16. |
| bind `App\Finance\DisputedAmounts` | `disputedFor(OrderDue): Money` | **6c binds** open disputes; reminders exclude that part. Default `NoDisputes`. |

### Correction primitive (for 6c)

Money is never updated or deleted. `app(MoneyLedger::class)` inside the transaction that locked the orders
(`FinanceLock::orders()`):

- `reverse(MoneyJournal $journal, string $reason, ?User $actor, string $source): MoneyJournal` — the exact negation of
  one journal, at most once (`reverses_journal_id` unique), refused if any place of the payment would go negative
  (so a collection is reversed only after its allocations). A reversal is never reversed — write the effect again.
- `reversePayment(Payment, $reason, $actor, $source): list<MoneyJournal>` — every open journal of the payment, newest
  first; afterwards the payment contributes nothing. The payment and its receipt stay as records.
- `write(JournalKind, Payment, list<{order_id, due_id, amount}>, …)` — the low-level writer the rest uses (moves sum
  to zero, a collection sums to the payment, no negative place, no due above its amount).

6c's M-11-T07 correction = `reversePayment()` + set the payment `corrected` (M-11-T07 is in `PaymentStatus::transitions()`)
+ `CollectionConditions::shortfalls()` to show affected stages (never un-complete them). A refund (M-12) reverses the
payment's **latest allocations first** (deallocation journals), then records the money that left; never more than
`array_sum(placesOf())` minus pending refunds. After any change call `Allocator::trim()/settle()` and
`CollectionConditions::evaluate()` under the order lock.

### Writes (6b)

Every write: idempotency key (`FinanceWrite`, scope `finance.*`) where money moves, `lock_version` of the order's
finance head (`FinanceLock::versionOf(Order)`; 409 `stale_version`), `LockingTransaction`, orders locked first (id
order), `state_transitions` (M-10, M-11) + `audit_events` (area `finance`) in the same transaction, outbox messages.

| Action | Permission | Notes |
|---|---|---|
| `M11Payment\T01RecordPayment::handle($user, $order, {amount, method transfer\|cash\|card\|cheque, paid_on, reference (required for transfer/cheque), note?, split?[{order_id, amount}], acknowledge_similar?}, UploadedFile $evidence, $lockVersion, $key)` | P-39 on every order touched | M-11-T00+T01, one receipt `RC-YYYY-000123`, allocation oldest first, INT-07. 409 `duplicate_reference`, `matching_proof`, `similar_payment`; 422 split above the payment / other customer. |
| `M10Proof\T01ConfirmProof::handle($user, $proof, {amount, paid_on, reference?, acknowledge_similar?, link_payment_id?}, $proofLockVersion, $key)` | P-40 | Links to the existing payment of the same trusted reference (or `link_payment_id`) — no new money; otherwise one payment + receipt. 409 `not_from_*`, `reference_conflict`, `similar_payment`. |
| `M10Proof\T02RequestProofCorrection::handle($user, $proof, $reason, $lockVersion)` | P-40 | Reason sent to the customer (outbox). File kept. |
| `M10Proof\T04CancelProof::handle($user, $proof, $reason, $lockVersion)` | P-40 | Only before money. |
| `Finance\UpdateSchedule::handle($user, $order, {dues: [{name{ar,en?}, reference, basis amount\|percent\|rest, value "5000.00"\|"50"\|null, event agreement\|payment_stage\|date, due_date?}]}, $lockVersion, $key)` | P-38 | 409 `no_agreement`; 422 above the agreement, one `rest`, below what a due holds (`due_has_allocations`). |
| `Finance\ReallocatePayment::handle($user, $payment, $order, {allocations: [{due_id, amount}]}, $lockVersion, $key)` | P-38 | Within the payment's share on the order and each due's remaining. |
| `Finance\TransferCredit::handle($user, $from, {to_order_id, amount, reason?}, $lockVersion, $key)` | P-38 on both | Same customer; credit only (409 `not_enough_credit`); no receipt. |
| `CustomerProofs::submit / resubmit / withdraw(CustomerActor, …)` | P-68 (customer portal, P8) | M-10-T00/T03/T04. File via `AttachmentService::storeFromCustomer()`. |
| `Gateway\GatewayPayments::begin(CustomerActor, Order, Money, $gatewayKey, $returnUrl)` | P-69 (P8) | Pending payment (M-11-T00) + provider URL. |
| `Gateway\GatewayPayments::applyWebhook($gatewayKey, Request)` | provider (verified signature) | The **only** way an online payment becomes money: `PaymentGateway::verify()` first; T02/T03/T04/T05/T06, T08 on replay; a late success after failure is referred (notification), never posted. No gateway bound in v1 (`GatewayRegistry` empty → 404). |
| `CollectionReminders::runDue(now)` (`finance:remind`, hourly) / `preview($user, $dueIds)` / `send($user, $dueIds)` | scheduler / P-37 | Inside `WorkSettings` reminder hours; due soon (≤ 2 days, once per date), overdue (weekly), manual (daily); disputed part excluded. |

Events: `AgreementApplied` → `SyncScheduleOnAgreement` (schedule from the facility template `half`, allocation of the
deposit, INT-07). Notifications: `finance.receipt_recorded`, `finance.proof_submitted`, `finance.late_gateway_result`
(link opens `orders.finance`, re-checks P-37). Customer messages (SMS + mail): `receipt:{id}`, `proof:{id}:correction`,
`proof:{id}:amount`, `due:{id}:{soon|overdue}:{period}`.

### HTTP (screens)

Routes in `src/routes/finance.php`, `auth` + `verified`, writes throttled by `records-create`. JSON writes answer
`{finance: Payload}` (+ `payment_id`, `receipt`).

| Method & path | Name | Screen |
|---|---|---|
| `GET orders/{order}/finance` | `orders.finance` | `FinOrder`, `FinOrderPaid`, `FinSales` (no P-37 → statuses only), `FinOrderMobile`, `FinEnglish`, `FinDark` — Inertia `orders/finance`; JSON with `Accept: application/json` |
| `POST orders/{order}/finance/payments` (multipart) | `orders.finance.payments.store` | `FinRecordPayment` |
| `PUT orders/{order}/finance/schedule` | `orders.finance.schedule` | `FinSchedule` |
| `POST orders/{order}/finance/transfers` | `orders.finance.transfers` | `FinAllocate`, `FinSettle` (credit part) |
| `POST finance/payments/{payment}/allocation` | `finance.payments.allocation` | allocation review (FIN-06) |
| `POST finance/proofs/{proof}/confirm` · `/correction` · `/cancel` | `finance.proofs.*` | `FinReceiptReview`, `FinReceiptMobile`, `FinReceiptFix` |
| `GET finance` | `finance.index` | `FinHome` (P-37) |
| `POST finance/reminders/preview` · `POST finance/reminders` | `finance.reminders.*` | `FinReminder` |
| `GET reports/collection?branch_id=` | `reports.collection` | `ReportCollection` (P-60 + P-37) |
| `POST webhooks/payments/{gateway}` | `webhooks.payments` | provider notifications (no session/CSRF) |

Payload (`FinancePresenter::forOrder`): `amounts_visible, lock_version, has_agreement, agreement_version,
summary{agreed_minor, collected_minor, remaining_minor, excess_minor, due_now_minor, credit_minor, unscheduled_minor,
settlement_needed_minor} | null, dues[{id, reference, name, basis, event, due_on, status, amount_minor?, allocated_minor?,
remaining_minor?}], payments[{id, receipt_number, method, paid_on, reference, origin, status, evidence[], amount_minor?,
share_minor?, allocations?}], proofs[{id, status, replaced, claimed_minor?, matching_receipt, files[], …}],
conditions[{reference, basis, threshold_minor?, met, verified}], shortfalls[], can{record, review, schedule, allocate},
customer_orders[]`. Without P-37 every `*_minor` key, `summary` and file links are absent — render «hidden», never 0.

Not in 6b: refunds (M-12), corrections UI (M-11-T07), disputes, external invoices (6c); commissions (6d); customer
portal screens and a real gateway (P8).
