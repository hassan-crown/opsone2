# P9 — Reports: metrics definitions and contract

> Decision record: `docs/decisions/0026-reports.md`. Code: `src/app/Reports`. Requirements: SHR-12, SHR-13, SHR-15;
> permissions P-60 (basic reports), P-61 (branch comparison, license capability `branch_comparisons`), P-62 (export).

## Principles

1. **One definition per number.** Every figure a report shows is registered in `App\Reports\MetricCatalogue`
   (key, kind, VAT treatment, grant, sources, formula). `ReportResult::metric()` refuses an unregistered key, and a test
   asserts it. The tables below are generated from the catalogue.
2. **Computed from sources, never from counters.** Money from `money_entries`/`money_journals`, `agreements`, `refunds`,
   `commission_entries`; movements from `stock_movements`; time-based facts from `state_transitions`, `tasks`,
   `appointments`. No denormalized counter column is read (the P9 index work by p9-perf does not change this).
3. **Money in halalas** (integers, `_minor` suffix); quantities in thousandths (`_milli`); VAT stated per metric:
   `gross` as the source stores it, `net` before VAT. Agreed value is shown gross, net and VAT separately from the
   agreement's own columns; purchase and valuation figures are VAT-exclusive (`net`).
4. **A purchase reference is never a value.** Sales are summed per order; the reference (a customer basket grouping)
   and its orders are never added together (SHR-12 acceptance).
5. **Scope then grant.** Counts use the viewer's P-60 scope over the order/branch. Money uses the narrower set
   P-60 ∧ P-37 over each order (P-82 for purchase cost and valuation, P-94 for commissions). An amount the viewer may
   not see is *absent* from the payload, table, chart and export — never `0`. The branch filter is an AND on top.
   `department`-scoped P-60 grants match nothing (branch/region/tenant only), the same rule as the other list screens.
6. **The table is the export.** Each report returns summary metrics, charts and one flat table; the screen and the CSV
   are rendered from the same rows (cap `ReportResult::ROW_CAP` = 1000, with a `truncated` flag).
7. **Dates.** A range is inclusive days in the facility time zone, bounded to 400 days. Each metric states which date
   it filters on (created, approved, decided, occurred, paid …).

## Reports and who sees them

| Key | Screen | Needs | Notes |
|---|---|---|---|
| `sales` | `ReportsHome`, `reports/sales` | P-60 | funnel: interest → order → agreement → completed / cancelled (SHR-15) |
| `collection` | `ReportCollection` (`/reports/collection`, P6b page) | P-60 + P-37 | the P6b screen reads the same `CollectionMetrics` |
| `refunds` | `reports/refunds` | P-60 + P-37 | executed, pending, corrections, disputes |
| `commissions` | `reports/commissions` | P-60 + P-94 | accrual is signed differences; a cancellation lowers it |
| `workflow` | `reports/workflow` | P-60 | completed, average time, overdue (no due date ⇒ never overdue) |
| `appointments` | `reports/appointments` | P-60 | cancelled counted apart |
| `inventory` | `reports/inventory` | P-60 + P-48 (+ history grant on the location) | valuation cost cells need P-82 |
| `procurement` | `reports/procurement` | P-60 | cost columns/metrics need P-82 |
| `returns` | `reports/returns` | P-60 | returns and accepted cancellations |
| `store` | `reports/store` | P-60 + license `ready_goods` | |
| `compare` | `ReportCompare` | P-61 (+ `branch_comparisons`) | each row = the same reports run for one branch |

Without the `branch_comparisons` capability the comparison is refused (403) and `ReportsHome` shows the `locked`
(`ReportsBasic`) notice; every basic report stays available (SHR-13). A user without P-61 on a plan that has it sees
nothing (`hidden`).

## HTTP contract (JSON when `Accept: application/json`, Inertia otherwise)

| Route | Name | Result |
|---|---|---|
| `GET /reports` | `reports.index` | `{reports[], compare, overview, filters, branches, can_export}` |
| `GET /reports/{key}` | `reports.show` | `{report, filters, branches, can_export}` |
| `GET /reports/compare` | `reports.compare` | same shape |
| `GET /reports/exports` | `reports.exports.index` | the user's own exports |
| `POST /reports/exports` | `reports.exports.store` | `{report, from, to, branch_ids[]}` → 201 (queued) |
| `POST /reports/exports/{uuid}/link` | `reports.exports.link` | `{url, expires_in}`; 410 if expired, 404 if not yours |
| `GET /reports/exports/{uuid}/download` | `reports.exports.download` | signed, 5 min, bound to the user; CSV |

Filters: `from`, `to` (`Y-m-d`, default month start → today), `branch_ids[]`.
Payload: `{key, range, branch_ids, amounts_visible, metrics[{key,kind,value}], charts[{key,type,unit,points[{label,value,enum?}]}], table{columns[{key,type}],rows[],truncated}}`.
Column types: `text int money qty date datetime hours enum percent`. Labels: `reports.metric.<key>`, `reports.col.<key>`,
`reports.chart.<key>`, `reports.enum.<value>`.

## Exports (NFR-16)

- Requested by a P-62 holder who can open that report; a `report_exports` row + audit `report_export.requested`, then
  `GenerateReportExport` on the queue (`afterCommit`, one try). The build re-resolves the user's **live** grants: lost
  access ⇒ `failed/access`, no file.
- CSV for spreadsheets: UTF-8 BOM, CRLF, text cells quoted, **formula injection neutralized** (a cell starting with
  `= + - @`, tab, CR or LF — after optional spaces — gets a leading `'`); money as decimal riyals, quantities with three
  decimals, never from user text. Headers and enum values use the requester's locale at request time.
- Private file on the attachments disk (`exports/YYYY/MM/<uuid>.csv`), retained `REPORTS_EXPORT_RETENTION_HOURS`
  (24); `reports:purge-exports` (hourly) deletes it and audits `report_export.purged`.
- The user gets in-app notification `report.export_ready` (mail by preference). Downloading needs a signed link valid
  `REPORTS_EXPORT_LINK_TTL` minutes (5), bound to the user id, and re-checks ownership, grants and expiry; each download
  is audited. Throttle `report-exports` (20/hour/user) on requests.

## Chart colours (Q-52 b)

Charts use the design tokens `--chart-1…5` (defined in `resources/css/app.css`, light and dark). The one place that maps
series to colours is `resources/js/components/reports/chart-colors.ts`; the designer's palette (Q-52 a) replaces it
before launch. No hard-coded hex values in chart code.

## Metric catalogue

### `sales`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `sales.orders_created` | count | none | — | orders | Orders created in range. |
| `sales.interests_converted` | count | none | — | interests | Interests (branch in scope) whose one-time conversion converted_at falls in range. |
| `sales.agreements_approved` | count | none | — | agreements | Orders whose first agreement version was approved in range. |
| `sales.agreed_gross_minor` | money | gross | P-37 | agreements | Sum of the ACTIVE agreement total of orders first approved in range, excluding cancelled orders. |
| `sales.agreed_net_minor` | money | net | P-37 | agreements | Same set, sum of net_minor. |
| `sales.agreed_vat_minor` | money | none | P-37 | agreements | Same set, sum of vat_minor. |
| `sales.completed` | count | none | — | state_transitions(M-04,M-05) | Orders whose transition to execution_finished (custom) or delivered (ready) occurred in range. |
| `sales.cancelled` | count | none | — | state_transitions(M-04,M-05) | Orders whose transition to cancelled occurred in range. |
| `sales.open_orders` | count | none | — | orders | Orders now in an active status (not draft, cancelled, closed, delivered, execution_finished). |

### `collection`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `collection.collected_minor` | money | gross | P-37 | money_entries, money_journals | Sum of entries of collection journals created in range. |
| `collection.corrections_minor` | money | gross | P-37 | money_entries, money_journals | Sum of entries of reversal journals in range (negative). |
| `collection.transfers_minor` | money | gross | P-37 | money_entries, money_journals | Sum of entries of transfer journals in range, on in-scope orders. |
| `collection.refunded_minor` | money | gross | P-37 | money_entries, money_journals | Sum of entries of refund journals in range (negative: money that left). |
| `collection.net_minor` | money | gross | P-37 | money_entries, money_journals | Sum of every entry in range = collected + corrections + transfers + refunded. |
| `collection.overdue_minor` | money | gross | P-37 | order_dues, money_entries | Remaining (due − allocated) of dues whose derived date is before today. |
| `collection.open_minor` | money | gross | P-37 | order_dues, money_entries | Remaining of all open dues (aged or not). |

### `refunds`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `refunds.requested_count` | count | none | P-37 | refunds | Refunds created in range. |
| `refunds.executed_count` | count | none | P-37 | refunds | Refunds executed in range. |
| `refunds.executed_minor` | money | gross | P-37 | refunds | Sum of amount of refunds with status executed and executed_at in range. |
| `refunds.open_minor` | money | gross | P-37 | refunds | Sum of refunds now requested, in progress or unresolved. |
| `refunds.corrections_count` | count | none | P-37 | payment_corrections | Payment corrections made in range. |
| `refunds.open_disputes` | count | none | P-37 | financial_disputes | Disputes not yet resolved. |

### `commissions`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `commissions.accrued_minor` | money | none | P-94 | commission_entries | Sum of signed entries created in range (corrections included, never netted away). |
| `commissions.paid_minor` | money | none | P-94 | commission_payouts | Sum of payouts with status paid and paid_on in range. |
| `commissions.balance_minor` | money | none | P-94 | commission_entries, commission_payouts | All entries to the end of range minus payouts paid to the end of range. |

### `workflow`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `workflow.tasks_completed` | count | none | — | tasks | Tasks completed in range. |
| `workflow.avg_completion_hours` | hours | none | — | tasks | Mean of completed_at − created_at in hours over those tasks. |
| `workflow.tasks_overdue` | count | none | — | tasks | Open tasks with a due date before now (no due date is never overdue). |
| `workflow.stages_completed` | count | none | — | order_stages | Stages completed in range. |

### `appointments`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `appointments.scheduled` | count | none | — | appointments | Non-cancelled appointments starting in range. |
| `appointments.done` | count | none | — | appointments | Those with status done. |
| `appointments.no_show` | count | none | — | appointments | Those flagged no_show. |
| `appointments.cancelled` | count | none | — | appointments | Cancelled appointments starting in range. |

### `inventory`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `inventory.items_in_stock` | count | none | — | stock_balances | Item/location balances with on_hand > 0. |
| `inventory.below_reorder` | count | none | — | stock_balances, stock_items | Items whose available (on_hand − reserved, all in-scope locations) is below the reorder level. |
| `inventory.valuation_minor` | money | net | P-82 | stock_balances, purchase_order_lines | Σ on_hand × weighted average net cost per unit (accepted purchase lines, VAT excluded); items never purchased are unvalued. |
| `inventory.unvalued_items` | count | none | P-82 | stock_balances, purchase_order_lines | Stocked items with no accepted purchase cost. |
| `inventory.received_milli` | quantity | none | — | stock_movements | Σ positive on_hand_delta in range. |
| `inventory.issued_milli` | quantity | none | — | stock_movements | Σ |negative on_hand_delta| in range. |

### `procurement`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `procurement.orders_placed` | count | none | — | purchase_orders | Purchase orders ordered in range. |
| `procurement.ordered_net_minor` | money | net | P-82 | purchase_orders | Σ net_minor of POs ordered in range, not cancelled before ordering. |
| `procurement.ordered_gross_minor` | money | gross | P-82 | purchase_orders | Σ gross_minor of the same set. |
| `procurement.remaining_milli` | quantity | none | — | purchase_order_lines | Σ max(quantity − accepted, 0) on ordered / partially received POs (rejected never satisfies). |
| `procurement.late_orders` | count | none | — | purchase_orders | Ordered / partially received POs with expected_on before today. |

### `returns`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `returns.requested` | count | none | — | order_returns | Returns created in range. |
| `returns.accepted_milli` | quantity | none | — | order_return_lines | Σ accepted quantity of returns created in range. |
| `returns.refund_value_minor` | money | gross | P-37 | order_returns | Σ settlement_minor of returns created in range. |

### `cancellations`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `cancellations.confirmed` | count | none | — | order_cancellations | Cancellations decided (confirmed) in range. |
| `cancellations.partial` | count | none | — | order_cancellations | Of those, scope partial. |
| `cancellations.retained_minor` | money | gross | P-37 | order_cancellations | Σ retained_minor of those cancellations. |
| `cancellations.open_settlement_items` | count | none | P-37 | settlement_items | Settlement items still open. |

### `store`

| Metric | Kind | VAT | Grant | Sources | Formula |
|---|---|---|---|---|---|
| `store.checkouts` | count | none | — | store_checkouts | Checkouts created in range. |
| `store.paid` | count | none | — | store_checkouts | Checkouts paid in range. |
| `store.paid_total_minor` | money | gross | P-37 | store_checkouts | Σ total_minor of checkouts paid in range. |
| `store.discount_minor` | money | gross | P-37 | store_checkouts | Σ discount_minor of those checkouts. |
| `store.conversion_percent` | percent | none | — | store_checkouts | paid ÷ created in range × 100, one decimal. |
