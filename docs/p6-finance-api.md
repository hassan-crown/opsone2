# P6 finance — service API (6a: money foundation and agreement)

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
| bind `App\Agreements\CollectedAmounts` | `collectedFor(Order): Money` | 6b binds its ledger (net of refunds). Read inside the activation transaction for `settlement_needed_minor` (AGR-06: 20,000 → 18,000 with 19,000 collected → 1,000 to settle). The default `NoCollections` returns 0. |

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
