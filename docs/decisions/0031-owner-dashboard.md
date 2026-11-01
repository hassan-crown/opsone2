# ADR-0031: Owner executive dashboard

- Status: accepted (2026-11-01).
- Builds on: ADR-0026 (reports and metric catalogue), ADR-0027 (performance), ADR-0005 (access model).
- Definitions: `docs/p9-metrics.md` (section `dashboard`). Code: `App\Reports\Dashboard`, `ExecutiveDashboardController`,
  `resources/js/pages/reports/executive.tsx`.

## Decision

1. **One page, `GET /executive`** (`executive`), the landing page after sign-in for whoever may open it
   (`ExecutiveLoginResponse`; an intended deep link still wins). It is also the first navigation item for those people;
   `/dashboard` stays the work-center home.
2. **Access = P-60 ∧ P-37.** The owner and general manager hold both at tenant scope, a branch manager at branch scope,
   so the same code shows each their own slice; the scope is the existing `ReportScope` (branch filter is an AND).
   Supervisors (reports, no money), sales and executors get 403. Finance also qualifies (it has both grants) and sees
   only its own branches. No new permission was added: the dashboard is a view over data the viewer may already read.
3. **No new ad-hoc queries for numbers P9 has.** The KPIs run `SalesReport`, `CollectionAgingReport` and `WorkflowReport`
   in a new summary-only mode (`ReportScope::$tables = false` skips the row listings). The figures P9 lacked are
   registered in `MetricCatalogue` as `dashboard.*` (average order value, interest conversion, late orders,
   installations in 14 days) and documented in `docs/p9-metrics.md` (a test enforces it). Branch comparison reuses
   `BranchComparison::rows()`.
4. **Period**: this month / last 30 days / this quarter resolve on the server in the facility time zone; `custom`
   takes `from`/`to` with the existing 400-day bound. Branch filter = `branch_ids[]`.
5. **Charts: no chart library.** The reports already draw their charts as small accessible SVG/HTML components
   (`--chart-N` tokens, ADR-0026). The dashboard follows: an SVG grouped-column trend (sales vs collections, six months,
   mirrored in RTL) and HTML ranking bars (funnel, stages, branches, salespeople, executors) that reflow at 390 px,
   carry every value as text, and are links. Adding a library for five simple charts would cost bundle size and
   theming/RTL work for no feature we use.
6. **Drill-down**: every tile and bar is a link to the existing filtered list or report (orders list `branch`/`assignee`/
   `status`, sales and workflow reports with the same range and branches, collection report, interests, calendar).
   The destination re-checks the viewer's own grants.
7. **Bounds and indexes**: all aggregates are range- or branch-bounded group-bys over the viewer's order set; the trend
   is six month-sized sums; the stage chart reads at most 5,000 in-progress stages (one per active order). One additive
   migration (`add_dashboard_indexes`): `agreements(order_id, approved_at)`, `tasks(completed_by, completed_at)`,
   `appointments(kind, starts_at)`, `interests(branch_id, created_at)`.

## Definitions worth knowing

- *Sales booked* = active agreement total of orders whose first agreement was approved in range (cancelled excluded);
  *collected* = collection-journal entries in range; *outstanding/overdue* = open dues (aging bounded to 500 orders,
  as P6b/P9). Top salespeople rank by booked value per `orders.assignee_id`.
- The funnel counts each step's own events in the period (new interests → of those, converted → agreements first
  approved → orders completed); the steps are different cohorts, so a later step can exceed an earlier one. That is
  stated on the page.

## Verification

`tests/Feature/Reports/ExecutiveDashboardTest.php`: access matrix, owner vs branch-manager scoping on a two-branch
fixture, the numbers (booked, average order, collected, trend, funnel) against hand-computed values and against the
sales report, period resolution and bounds, locked comparison, and the post-login landing.
