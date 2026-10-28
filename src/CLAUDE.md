# OpsOne — Laravel app (`src/`)

> Root `CLAUDE.md` and `.claude/rules/*.md` take precedence. Reports and plans in Arabic; code in English.

## What this app is

- OpsOne **tenant app**: bilingual (ar/en) operations SaaS for made-to-order businesses (kitchens, furniture,
  curtains) with an optional ready-goods store. Scope: domains 01–07 and 09, plus signed-license **verification**
  from domain 08.
- **Single-tenant code, one isolated deployment per tenant** (own DB, files, Redis, `APP_KEY`, secrets) —
  `docs/decisions/0002-tenant-isolation.md`. Do NOT add `tenant_id` scoping or a tenancy package.
- The central app (plans site, subscriber accounts, provisioning, license signing) lives in a separate repository —
  `docs/decisions/0003-central-app-separation.md`. This app holds only the license public key, never a private key.

## Stack (installed versions in `docs/tech-stack.md`)

- Laravel 13, PHP 8.5, MariaDB 11, Redis 7; React 19 + Inertia 3 + TypeScript + Tailwind 4 + shadcn/ui.
- Official React starter kit; auth via Fortify (email verification, 2FA, passkeys, password confirmation). Public
  registration is disabled: staff join by invitation only (M-01).
- Wayfinder for typed routes; output in `resources/js/{actions,routes,wayfinder}` is generated — never edit it.
- Build: Vite+ (`vp`) with pnpm 12 (`packageManager` pinned).

## Layout

- `app/Access` — central Gate (`Access::can($user, 'P-xx', $record)`), permission catalogue, scope resolver,
  session revocation — `docs/decisions/0005-access-model.md`. Domain records implement `Access\Contracts\ScopedRecord`.
- `app/Notifications`, `app/Integrations` — in-app notifications + outbound outbox (`Outbox`, `Notifier`; send mail only through them) and facility SMTP/SMS settings — `docs/decisions/0007-notifications-outbox.md`.
- `app/Actions/StateMachines/M{nn}*/T{nn}*` — one Action per transition; log via `TransitionRecorder` (`state_transitions`).
- `app/Licensing` — signed-license verification (ADR-0004); use `CurrentLicense` (`allows()`, `limit()`, `status()`), never read the file.
  The Gate's license step and seat/branch limits (`Access\LicenseLimits`, inside the adding transaction) are in ADR-0005.
- `app/Audit` — append-only audit trail: `Audit::record(AuditAction::X, $subject, $actor, before:, after:)` INSIDE the
  change's transaction; `AuditLog` screen (P-63). `app/Files` — private attachments (`AttachmentService`, `AllowedAttachment`
  rule, signed links re-checking access on the owner, `storage_mb` quota) — `docs/decisions/0006-audit-and-attachments.md`.
- `app/Catalog` + `app/Actions/Catalog` + `routes/catalog.php` — made-to-order products, materials, ready goods/variants (P-11/P-12/P-13, `ready_goods`): money in minor units (`MinorUnits`), JSON `{ar,en}` text (`App\Support\Translatable`), append-only `catalog_versions`, soft-disable, single write path `CatalogWriter` — `docs/decisions/0010-catalog-versioning.md`.
- `app/SupportAccess` + `M38SupportAccess` — OpsOne support delegation: `is_support` identities act only through a live,
  time-boxed, read-only grant (`EnforceSupportAccess`) — `docs/decisions/0008-support-access.md`.
- `app/Customers`, `app/Actions/Customers`, `M03Interest` — customers, contacts and interests (phone normalization, scoped lists via `Access\ScopedQuery`, hidden fields per P-10) — `docs/decisions/0009-customers-and-interests.md`. Bilingual JSON columns use `App\Support\Translatable\TranslatableString`.
- `app/Orders`, `app/Actions/Orders`, `app/Actions/BranchTransfer`, `M04CustomOrder`/`M05ReadyOrder`, `routes/orders.php` — orders (custom opens in progress without price, ready as draft), frozen lines (`catalog_version_id` + snapshot), gapless numbers (`Support\GaplessSequence` inside `Support\LockingTransaction`), idempotent create (`Support\Idempotency`), `lock_version` (409 `StaleRecord`), money only with P-37, ready part needs `ready_goods` via `LicensedRecord`, atomic branch transfer — `docs/decisions/0011-orders.md`, contract `docs/p4-orders-api.md`.
- `app/Workflow`, `app/Actions/StateMachines/M06Stage|M07Task|M16Appointment|M23Flow|M27ActionResult`, `M04CustomOrder/T01..T03`, `app/Actions/Workflow` — workflow engine (P5): fixed action catalogue (`Workflow\Catalogue\ActionType`), one facility flow with immutable versions pinned per order, `FlowCheck`, `StageEngine` (the flow service), tasks with department inbox and eligibility, appointments with member-row-locked conflict checks, measurements, `WorkSettings` (Q-31); results from P6/P7 only via `ActionResults::verifyFromSource()` — `docs/decisions/0012-workflow-engine.md`, service contract `docs/p5-engine-api.md`.
- `app/Money` — P6 money foundation: `Money` (integer halalas, overflow guard, `allocate()` without losing a halala), one rounding policy `Rounding` (half-up per line, Q-34 a), `VatCalculator` (per line, inclusive/exclusive, Q-35 a); facility `FinanceSettings` + `DiscountLimit` (`SettingsSales`). Never a float for money.
- `app/Agreements`, `app/Actions/StateMachines/M08Document|M09Change|M26Decision`, `routes/agreements.php` — agreement inside the order (P6a, domain 04): quote/design/contract documents with fixed content hash, discount limit + referral, external approval with evidence or customer decision (Q-40 a), immutable agreement versions, agreement change holding affected work (`order_actions.held_by_change_id`), P5 result signals (`AgreementSignals`), amounts hidden without P-37/P-29 (`AgreementPresenter`); read API for 6b/6d `OrderAgreement`, `AgreementApplied`, `CollectedAmounts` — `docs/decisions/0013-money-and-agreement.md`, contract `docs/p6-finance-api.md`.
- `app/Finance`, `app/Models/Finance`, `M10Proof`/`M11Payment`, `app/Actions/Finance`, `routes/finance.php` — order money (P6b, domain 05): dues schedule following the agreement, payments with one gapless receipt each (`RC-YYYY-000123`), customer transfer proofs, append-only money journal (`MoneyLedger`, reversal is the only correction), allocation oldest due first, stage collection condition (INT-07), verified-webhook-only online payments (no gateway in v1), reminders in reminder hours — `docs/decisions/0014-collections.md`, contract `docs/p6-finance-api.md`.
- `app/Commissions`, `app/Models/Commissions`, `M33Payout`, `app/Actions/Commissions`, `routes/commissions.php` — sale ownership and commissions (P6d): one sale per order pinned with its rate at the first agreement, accrual as append-only signed differences re-derived on every collection change (`CollectionConditions::evaluate` → `CommissionAccrual`), eligible lines before VAT (Q-61 default), payout holds per order (`SettlementHolds`, 6c decorates), M-33 payouts with proof, owner/rate corrections approved by another P-97 holder; `own` scope = the sale's member — `docs/decisions/0016-commissions.md`, contract `docs/p6-finance-api.md` «6d».
- `app/Finance/Settlement`, `M12Refund`/`M39Dispute`/`M40Document`, `M11Payment/T07CorrectPayment`, `routes/finance-refunds.php` — settlement (P6c): refunds reserving their limit under the order lock before execution (`RefundLimits`), manual payout with proof and gapless vouchers `RF-YYYY-000123` (`RefundPosting`, a `refund` journal after deallocating latest first), corrections as reversal + re-entry with `CR-YYYY-000123`, financial disputes holding reminders (`OpenDisputeAmounts`), external accounting documents unique per source (never a tax invoice from OpsOne) — `docs/decisions/0015-refunds-disputes.md`, contract `docs/p6-finance-api.md`.
- `app/Inventory`, `app/Models/Inventory`, `app/Actions/Inventory`, `M24Reservation`/`M25Transfer`, `M05ReadyOrder/T01|T09`, `routes/inventory.php` — stock (P7a, domain 06): quantities in thousandths of one fixed unit per item (Q-43 a, `StockUnit`, `Quantity`), append-only `stock_movements` with balances projected in the same transaction by the single write path `StockLedger` (`SELECT … FOR UPDATE`, global lock order, never negative), locations as ScopedRecords (no branch = facility grants only), M-24 reservations on ready confirmation / custom materials (P-51), M-25 transfers, counts approved by P-49, `StockReceiver` seam for procurement — `docs/decisions/0017-inventory.md`, contract `docs/p7-inventory-api.md`.
- `app/Procurement`, `app/Models/Procurement`, `app/Actions/StateMachines/M13PurchaseOrder`, `routes/procurement.php` — suppliers and purchase orders (P7b, domain 06): fixed units with quantities in thousandths (Q-43), gapless `PO-YYYY-000123`, remaining = ordered − accepted (rejected never satisfies the order), over-receipt only after an explicit adjust (T04), cancel-remainder keeps what was received, receipts call `Inventory\Contracts\StockReceiver` once per accepted line inside the transaction, costs only with P-82 (keys omitted from the payload) — `docs/decisions/0017-procurement.md`.
- `app/Delivery`, `app/Models/Delivery`, `M14Delivery`, `routes/delivery.php` — delivery (P7c, domain 06): delivery notes with actual quantities per order line (partial allowed, never above the reservation / effective ordered quantity), recording in one transaction under the order lock — ready lines issue their reservations through `IssueReadyReservation`, gapless `DN-YYYY-000123` at recording, proof = receiver + ≥1 private file (Q-44 a), ready order T08, complete delivery of a custom order → `ActionResults::verifyFromSource(RecordDelivery)`, due event `delivery` = first confirmed note, amounts only with P-37 — `docs/decisions/0018-delivery.md`, contract `docs/p7-inventory-api.md` «Delivery».
- `app/Cancellation`, `app/Settlement`, `M34Cancellation`, `routes/cancellation.php` — cancellation (P7e, domains 02/06): impact review computed from the sources with a fingerprint the confirm must match (409 `preview_stale`), full/partial (agreement lines re-priced through `QuotePricing` + `AgreementActivation`, schedule synced, commission re-derived), tasks/appointments stopped, reservations released (partial quantity allowed), gapless `CN-YYYY-000123`, no automatic refund; **one settlement follow-up per order** (`SettlementFollowUps`, items followed from their sources, returns add theirs) — `docs/decisions/0020-cancellation.md`, contract `docs/p7-inventory-api.md` «Cancellation».
- `app/Returns`, `app/Models/Returns`, `M15Return`, `app/Actions/Returns`, `routes/returns.php` — ready-goods returns (P7d, domain 06): request on delivered lines (`RT-YYYY-000123`), acceptance holding eligibility under the order lock (delivered − accepted), receipts and one-time inspection (good `return_in`, damaged custody `return_damaged`, rejected nothing), withdrawal of the unreceived remainder, T04 counter movements, one M-12 refund per return limited by the settlement (`Returns\Contracts\ReturnRefunds`), open returns hold commission (`ReturnSettlementHolds`), settlement follow-up seam `Returns\Contracts\SettlementFollowUp` — `docs/decisions/0019-returns.md`, contract `docs/p7-inventory-api.md` «Returns».
- `app/Tickets`, `app/Models/Tickets`, `M21Ticket`, `app/Portal/CustomerTickets`, `routes/tickets.php` + `routes/customer-tickets.php` — customer support tickets (P8d, domain 09, SHR-08): M-21 transitions only through `TicketTransitions` (validated before any write), staff P-59 on the ticket (`TicketAccess`, scoped by branch/assignee), customer P-71 only via `PortalAccess::tickets()`; public messages vs internal notes (files hang on the message; `TicketPresenter` customer payload is an allow-list), notices without message text via `TicketNotices` — `docs/decisions/0023-tickets.md`.
- `app/Tasks`, `app/Http/Controllers/Tasks`, `routes/tasks.php` — P5 task screens (list/inbox, assign, task page, mobile executor home, measurement entry, home cards) on the engine's task Actions — `docs/p5-tasks-api.md`.
- `app/Portal`, `app/Http/Controllers/Portal`, `app/Http/Middleware/Portal`, `routes/customer.php` (`/portal`, `customer.*`) — customer workspace (P8b, domain 07): guard `customer` with its own session cookie (`UseCustomerSession`) and middleware group, never the staff Gate or `$request->user()`; passwordless OTP (`CustomerOtp`: hashed codes, SMS else account email, caps, no enumeration), session stamp for sign-out everywhere (`PortalSession`); every customer query through `PortalAccess` (foreign id = 404) and allow-list payloads (`PortalPresenter`); actions reuse `CustomerDecisions`, `CustomerProofs`, `CustomerAppointments`, `CustomerCancellations` — `docs/decisions/0022-customer-workspace.md`.
- `app/Actions/Fortify` — Fortify actions; `app/Http/{Controllers,Requests,Middleware}`; `app/Concerns` — shared validation rules.
- `resources/js/pages` — Inertia pages; `resources/js/components/ui` — shadcn/ui components.
- `routes/web.php`, `routes/settings.php`, `routes/account.php` (facility settings, plan/usage, ownership transfer, my sessions). Use `Route::get($uri, fn () => redirect($to))`, not `Route::redirect()`.
- `tests/Feature`, `tests/Unit` — Pest 5 (`tests/Pest.php`); kit tests are class-based PHPUnit and run under Pest.

## Commands (run from repo root, through Docker)

- `./manage.sh artisan test` — test suite (Pest).
- `./manage.sh artisan migrate` — never `migrate:fresh` / `db:wipe` without explicit approval.
- `./manage.sh node-build` — Wayfinder generate + `pnpm install` + build.
- `./manage.sh node-dev` — Vite dev server.
- `docker exec opsone_local_app ./vendor/bin/pint --test` / `./vendor/bin/phpstan analyse` (level 7).
- `docker compose --profile tooling run --rm node-build sh -lc 'pnpm run check && pnpm run types:check'`.

## Conventions

- Validate with Form Requests, authorize with policies/gates; keep controllers thin, use Actions for workflows.
- All user-facing strings translatable (ar/en); support RTL layouts.
- Laravel Boost is dev-only and exposed via root `.mcp.json`. Do NOT run `boost:install`.
