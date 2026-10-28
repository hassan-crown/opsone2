# P6 finance — service API (6a: money foundation and agreement · 6b: collections · 6c: refunds and settlement)

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
| `UpdateCashProofPolicy::handle($user, {cash_proof_required}, $lockVersion)` | the owner | Q-62: whether a cash collection needs a proof file (default true). Audited. |
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
| `M11Payment\T01RecordPayment::handle($user, $order, {amount, method transfer\|cash\|card\|cheque, paid_on, reference (required for transfer/cheque), note?, split?[{order_id, amount}], acknowledge_similar?}, ?UploadedFile $evidence, $lockVersion, $key)` | P-39 on every order touched | M-11-T00+T01, one receipt `RC-YYYY-000123`, allocation oldest first, INT-07. Evidence required (422 `evidence`) except cash when the owner turned `cash_proof_required` off — then `note` is required (Q-62). 409 `duplicate_reference`, `matching_proof`, `similar_payment`; 422 split above the payment / other customer. |
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

## 6c — refunds, corrections, disputes and external documents

Decisions: [ADR-0015](decisions/0015-refunds-disputes.md). Code: `app/Finance/Settlement`, `app/Models/Finance`
(`Refund`, `PaymentCorrection`, `FinancialDispute`, `ExternalDocument`), `app/Actions/StateMachines/M12Refund|M39Dispute|M40Document`,
`M11Payment/T07CorrectPayment`, `routes/finance-refunds.php`.

### Reading (6d, P7, reports)

| Call | Returns | Notes |
|---|---|---|
| `RefundLimits::refundable(Payment, int $orderId)` | `Money` | Payment's money on the order − reserved refunds (requested / in progress / unresolved). 0 unless confirmed. |
| `RefundLimits::reserved()/executed()/placed()` · `::freeCredit(int $orderId)` · `::freeCreditByPayment(int $orderId)` | `Money` / `array<payment, halalas>` | Credit no reservation holds (what may still be transferred), per payment: a payment's credit minus what its own pending refunds still need. `TransferCredit` takes only that. |
| event `Finance\Settlement\RefundExecuted` | `refundId, orderId, paymentId, amountMinor, number` | After commit, once per executed refund (T02/T05). 6d: negative commission difference; idempotent on `refundId`. |
| `DisputedAmounts` (bound to `OpenDisputeAmounts`) | `disputedFor(OrderDue): Money` | Open disputes on that due; reminders exclude it. |
| `SettlementPresenter::forOrder(Order, User)` | array | Screen payload (below). Caller checks P-16. |
| `SettlementWorkItems::for(Builder $orders)` | list | FinHome kinds `refund`, `dispute`. |

### Writes

Every write: idempotency key (required), the version read (finance head for T00 creations; the record's
`lock_version` for its transitions), order row lock first, `state_transitions` + `audit_events` in the transaction.

| Action | Permission | Notes |
|---|---|---|
| `M12Refund\T00RequestRefund::handle($user, $order, {payment_id, amount, method transfer\|cash\|card\|cheque, reason}, $lockVersion, $key)` | P-42 | Reserves at once. 409 `not_refundable`, `payment_not_on_order`. |
| `M12Refund\T01StartRefund::handle($user, $refund, {reference?}, $lockVersion, $key)` | P-43 | Sent; limit re-checked. |
| `M12Refund\T02ConfirmRefund::handle($user, $refund, {reference, note?}, UploadedFile $proof, $lockVersion, $key)` | P-43 | From requested (records T01) or in progress. Deallocates latest first, `refund` journal, voucher `RF-YYYY-000123`, INT-07, `RefundExecuted`. 409 `not_refundable` if the money moved away. |
| `M12Refund\T04RecordRefundFailure::handle(?$user, $refund, $reason, $lockVersion, $key)` | P-43 (or provider: `null` user) | Releases the reservation. |
| `M12Refund\T06CancelRefund::handle($user, $refund, $reason, $lockVersion, $key)` | P-42 | Requested only. |
| `M12Refund\T07RetryRefund::handle($user, $refund, {reference?}, $lockVersion, $key)` | P-43 | Failed → in progress; limit re-checked. |
| `M12Refund\RefundProviderResults::unresolved($refund, $ref)` / `::succeeded($refund, $ref)` | provider (P8) | T03 / T05 (`reference_mismatch`). |
| `M11Payment\T07CorrectPayment::handle($user, $order, $payment, {kind void\|amend, reason, amount?, method?, paid_on?, reference?}, ?UploadedFile, $lockVersion, $key)` | P-41 on every order the payment touched | Reversal journals + `corrected` + voucher `CR-YYYY-000123`; amend re-enters a new payment + receipt. 409 `payment_has_refunds`, `split_payment`, `nothing_changed`, `not_from_*`. |
| `M39Dispute\T00OpenDispute::handle($user, $order, {payment_id?\|refund_id?\|due_id?, subject, amount, amount_note?, responsible_id, reason}, $lockVersion, $key)` | P-85 | 409 `no_movement`; 422 amount above the movement without `amount_note`. |
| `M39Dispute\T01AwaitExternal` (party, note?) · `T02ResumeDispute` (note) · `T03ResolveDispute` ({outcome upheld\|partly_upheld\|rejected, resolution}, UploadedFile $proof) | P-85 | Never moves money. |
| `M40Document\T00RequestDocument::handle($user, $order, {kind, event, payment_id?, refund_id?, note?}, $key)` | P-44 | Requested. |
| `M40Document\T01AttachDocument::handle($user, $order, ?$document, {source, number, issued_on, amount, currency?} + links for a new one, UploadedFile, $lockVersion, $key)` | P-44 | T01 / T04 (after correction). 409 `duplicate_number` (per source). |
| `M40Document\T02AcceptDocument` · `T03RequestDocumentCorrection` (reason) | P-44 | Attached only. |

### HTTP (screens)

Routes in `src/routes/finance-refunds.php` (`auth` + `verified`, writes throttled by `records-create`). JSON writes
answer `{finance, settlement}` (+ `refund_id`, `number`, `correction_id`, `dispute_id`, `document_id`).

| Method & path | Name | Screen |
|---|---|---|
| `POST orders/{order}/finance/refunds` | `orders.finance.refunds.store` | `FinRefund` |
| `POST finance/refunds/{refund}/start` · `/confirm` (multipart) · `/fail` · `/retry` · `/cancel` | `finance.refunds.*` | `FinRefundExec` |
| `POST finance/payments/{payment}/correction` (multipart, `order_id`) | `finance.payments.correction` | `FinCorrect` |
| `POST orders/{order}/finance/disputes` | `orders.finance.disputes.store` | `FinDispute` |
| `POST finance/disputes/{dispute}/await` · `/resume` · `/resolve` (multipart) | `finance.disputes.*` | `FinDisputeResolve` |
| `POST orders/{order}/finance/documents` · `/documents/link` (multipart) | `orders.finance.documents.*` | `FinInvoiceLink` |
| `POST finance/documents/{document}/attach` (multipart) · `/accept` · `/correction` | `finance.documents.*` | `FinInvoiceLink` |
| `GET finance/invoices` | `finance.invoices` | `FinInvoices` (P-44) |

`orders/finance` gets a `settlement` prop (`SettlementPresenter::forOrder`): `amounts_visible, payments[{id,
receipt_number, status, refundable, correctable, split, amount_minor?, placed_minor?, reserved_minor?, refunded_minor?,
refundable_minor?}], refunds[{…, status, can{start, confirm, fail, retry, cancel}, amount_minor?}], corrections[{number,
kind, receipt_number, replacement_receipt, before_minor?, after_minor?}], disputes[{…, can{await, resume, resolve},
amount_minor?}], documents[{…, status, files (P-44), can{attach, review}, amount_minor?}], open_disputes,
customer_tax{vat_number, commercial_registration, missing[]}, members[], can{request_refund, execute_refund, correct,
dispute, documents}`. Without P-37 every `*_minor` key and money files are absent — render «hidden», never 0.

## 6d — sale ownership and commissions

Decisions: [ADR-0016](decisions/0016-commissions.md). Code: `app/Commissions`, `app/Models/Commissions`,
`app/Actions/StateMachines/M33Payout`, `app/Actions/Commissions`, `routes/commissions.php`.

### Hook for 6c (refunds, corrections, disputes, cancellations)

| Call | When | Effect |
|---|---|---|
| `CollectionConditions::evaluate($order, $paymentId, $actor, ?$commissionSource, ?$reason)` | After any ledger change, under the order lock (already the 6b contract) | Also re-derives the order's commission: one signed difference entry (source `order_collection:{payment}`, or the given source — 6c passes `refund:{id}` with the RF number and `correction:{CR}`), nothing when unchanged. |
| `app(CommissionAccrual::class)->recompute(Order $order, string $source, ?User $actor, ?string $reason)` | Inside the transaction that locked the order, when commission must follow without a ledger change (cancellation, a correction with its own source such as `refund:{id}` / `correction:{id}`) | `?CommissionEntry` — the difference written, or null. Idempotent. A negative difference after a payout is carried, never erasing the payout. |
| bind `App\Commissions\SettlementHolds` | Bound by 6c to `Finance\Settlement\SettlementCommissionHolds`: a reserving refund → `refund`, an unresolved dispute → `dispute`, else the default (`LedgerSettlementHolds`: money above the agreement) | `holdOf(Order): ?string` — `refund`, `dispute`, `cancellation`, `settlement` or null. A held order is left out of payouts; its accrual is still recorded; the member's other orders stay payable. Known codes have labels (`commissions.hold.*`). |

### Reading

| Call | Returns |
|---|---|
| `SaleOwnership::active(Order)` | `?OrderSale` — `user_id`, `rate_bp` (pinned), `rate_source`, `owner_source`, `agreement_id`, `pinned_at`. |
| `CommissionBasis::of(Order, OrderSale)` | `{eligible_minor, collected_minor, agreed_minor, target_minor}` — eligible lines before VAT × rate × allocated / agreed (Q-61 default). |
| `CommissionAccrual::accrued(OrderSale)` | int halalas accrued now. |
| `CommissionBalances::summary(int $userId, ?list<int> $saleIds)` / `::payable(int $userId, Carbon $until)` | Statement figures (`accrued, paid, reserved, held, available` — negative = carried) / what a payout would take now (`lines`, `amount_minor`, `held`). |
| `SaleScope::apply(Builder<OrderSale>, User, Permission = P94)` | Sales the viewer's grants cover (`own` = the sale's member). |

### Writes

| Action | Permission | Notes |
|---|---|---|
| `M33Payout\T00CreatePayout::handle($user, $member, {period_from, period_to}, $key)` | P-96 over the payout (member's scope) | Draft; `payout_open`, `own_payout`. |
| `M33Payout\T01ApprovePayout::handle($user, $payout, $lockVersion, $key, ?{method, paid_on, reference, note?}, ?UploadedFile $proof)` | P-96 | M-33-T01 (+T02 when execution and proof are given). Locks member then orders; `nothing_available`. |
| `M33Payout\T02RecordPayout::handle($user, $payout, {method transfer\|cash\|cheque\|payroll, paid_on, reference, note?}, UploadedFile $proof, $lockVersion, $key)` | P-96 | Reserved → paid. Once (`not_from_paid`). |
| `M33Payout\T03CancelPayout::handle($user, $payout, $reason, $lockVersion)` | P-96 | Draft/reserved → cancelled; lines released. |
| `M33Payout\T04CorrectPayout::handle($user, $payout, {amount "40.00", reason}, UploadedFile $proof, $lockVersion, $key)` | P-97 | Paid → corrected; the difference becomes a member adjustment. |
| `Commissions\RequestSaleCorrection::handle($user, $order, {kind owner\|rate, to_user_id?, rate_bp?, reason})` | P-98 (owner) / P-97 (rate) over the sale | `own_sale`, `correction_open`, `no_sale`, `no_change`. |
| `Commissions\DecideSaleCorrection::approve($user, $c, $lockVersion)` / `reject($user, $c, $reason, $lockVersion)` / `cancel($user, $c, $lockVersion)` | P-97, not the requester nor an affected member / the requester | Approval supersedes the sale and moves the accrual (`transfer_out`/`transfer_in`). |
| `Commissions\UpdateCommissionSettings::handle($user, {enabled, default_rate_bp, manual_lines_eligible, roles[{role_template_id, rate_bp\|null}], members[{user_id, rate_bp\|null}]}, $lockVersion)` | P-95 | New sales only. |

### HTTP (screens)

Routes in `src/routes/commissions.php`, `auth` + `verified`, writes throttled by `records-create`. Payout writes
answer `{payout_id, statement: Payload}`; sale writes `{sale: Payload}` (+ `correction_id`, 201).

| Method & path | Name | Screen |
|---|---|---|
| `GET finance/commissions` | `finance.commissions` | `FinCommissions` (P-94) |
| `GET finance/commissions/members/{member}` · `GET finance/commissions/mine` | `finance.commissions.member` · `.mine` | `FinMyCommission` + `FinPayout` |
| `POST finance/commissions/members/{member}/payouts` | `finance.commissions.payouts.store` | `FinPayout` (draft) |
| `POST finance/commissions/payouts/{payout}/approve` (multipart; `pay_now` + execution + `proof`) · `/record` · `/cancel` · `/correct` | `finance.commissions.payouts.*` | `FinPayout` |
| `GET orders/{order}/sale` | `orders.sale` | sale owner and commission correction (P-94/P-97/P-98) |
| `POST orders/{order}/sale/corrections` · `POST sale-corrections/{c}/approve` · `/reject` · `/cancel` | `orders.sale.corrections.store`, `sale-corrections.*` | idem |
| `PUT settings/sales/commissions` | `settings.sales.commissions` | `SettingsSales` (commission part, P-95) |
