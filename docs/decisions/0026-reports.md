# ADR-0026: Reports, metrics definitions and exports (P9 part A)

- Status: accepted (2026-10-28).
- Scope: domain 09 — SRS-SHR-12, SHR-13, SHR-15; permissions P-60 (basic reports), P-61 (branch comparisons, license
  capability `branch_comparisons`), P-62 (export); screens `ReportsHome`, `Reports*`, `ReportCollection`,
  `ReportCompare`, `ReportsBasic` (locked variant), `ReportExport`.
- Builds on: ADR-0005 (Gate, scoped grants, license step), ADR-0006 (private files, signed links), ADR-0007 (notifier),
  ADR-0014 (money ledger), ADR-0017 (stock ledger). Definitions: `docs/p9-metrics.md`.
- Decided: Q-52 (b) — chart colours are derived temporarily from the design tokens (`--chart-1…5`), kept in
  `resources/js/components/reports/chart-colors.ts`, to be replaced by the designer's palette before launch.
- Not in this phase: scheduled/emailed reports, XLSX binary files (the CSV is spreadsheet-safe), department
  comparisons (branches only; P-61 department scope is not modelled), facility close-out readiness (Q-53, p9-ops).

## Decision

1. **One metrics layer.** `App\Reports\MetricCatalogue` registers every number once (kind, VAT treatment, grant, sources,
   formula). `ReportResult::metric()` accepts only registered keys. Computation lives in `App\Reports\Definitions\*`
   (one class per report) and `CollectionMetrics`, always from source records (ledger entries by journal kind, active
   agreements, refund/commission rows, stock movements, state transitions, tasks, appointments) — never from a counter
   column. The P6b collection screen now reads `CollectionMetrics` too, so the number exists once.
2. **Scope, then grant.** `ReportScope` gives the P-60 scope over orders and branch-bound records plus the branch filter
   (an AND). Money is computed only over P-60 ∧ P-37 orders (P-82 for cost, P-94 for commissions); an amount a viewer
   may not see is absent from payload, table, chart and CSV — never `0`. Money-only reports (collection, refunds,
   commissions) are refused without their grant; others stay available with counts only.
3. **SHR-15.** A converted interest or created order is not a completed sale: the sales report shows the funnel from its
   own sources (interest conversion, first agreement approval, completion transitions, cancellations). Agreed value is
   the *active* agreement of orders first approved in the range, minus cancelled orders, so partial cancellation (a
   re-priced agreement) and agreement changes flow through; commissions stay append-only signed differences.
4. **No double counting.** Sales sums are per order; a purchase reference is never added as a value.
5. **Branch comparison (SHR-13).** Each row is the same reports run restricted to one branch, so a compared figure equals
   that branch's own report. Refused (403) without P-61 or without the `branch_comparisons` capability; `ReportsHome`
   then shows `locked` (plan) or nothing (`hidden`, no grant). Basic reports are unaffected.
6. **Exports.** `report_exports` + job `GenerateReportExport` on the queue: authorize at request (P-62 and the report),
   build from the same code as the screen against the user's *live* grants at generation time, write a private file
   (attachments disk, `exports/…/<uuid>.csv`), notify (`report.export_ready`), audit (requested, generated, failed,
   downloaded, purged). The CSV is UTF-8 with BOM, CRLF, quoted text, formula injection neutralized (leading `'` for
   `= + - @`, tab, CR, LF); numbers are written from typed columns only. Download only through a signed link
   (5 min, bound to the user, re-checks ownership/grants/expiry); the file is purged after 24 h by `reports:purge-exports`.
7. **Bounds.** Range ≤ 400 days; tables capped at 1000 rows with a `truncated` flag shown on screen and in the export
   record; export requests throttled (`report-exports`, 20/hour/user).

## Consequences

- A new report adds a metric to the catalogue (and `docs/p9-metrics.md`, enforced by a test) and a `Report` class in the
  registry; its export and comparison need no extra code.
- Per-branch comparison costs one report run per branch (branches are bounded by the license).
- `OpenDues` bounds the aging to 500 orders (as in P6b); the report shows what that bound covers.
- Time-of-day grouping uses stored UTC timestamps converted to the facility time zone for dates.

## Verification

Pest (`tests/Feature/Reports`): hand-computed fixtures for sales (incl. partial/full cancellation, purchase reference),
collection (collected/correction/refund/net, aging), refunds, commissions, workflow, appointments, inventory valuation,
procurement, returns, store; scope and grant hiding; comparison gate with and without the capability; export rows equal
screen rows, CSV injection neutralized, link expiry, retention purge, access lost between request and build.
