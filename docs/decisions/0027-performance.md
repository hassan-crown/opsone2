# ADR-0027: P9 performance — dataset, budgets and the request-level caches

- Status: accepted (2026-10-28).
- Scope: NFR-13 (paged, filtered reads within 2 s p95 at 20 concurrent users on the Q-55 volume), with NFR-04/NFR-05
  kept as invariants of the dataset (balanced money journals, stock balances = movements). Measurements and server
  tuning proposals: `docs/performance.md`.

## Decision

1. **Dataset.** `Database\Seeders\PerformanceSeeder` (local/testing only, never in `DatabaseSeeder`) generates the Q-55
   volume per facility — 5,000 customers, 20,000 orders, ≈200,000 stock movements — over four branches, two years and
   every order state, deterministically (fixed PRNG seed, anchor day = today or `PERF_SEED_ANCHOR`). Templates (staff,
   catalog, flow, one real order) go through the domain Actions; the volume is bulk-inserted inside one transaction with
   the write paths' invariants. Agreements are not generated: dues use dated events (documented limitation).
2. **Measurement.** `perf:probe` (local/testing only) replays 24 hot pages through the HTTP kernel in-process and
   reports p50/p95, query count, SQL time and payload; a navigation sends the Inertia once-props it already holds.
   Concurrency is checked separately over nginx + PHP-FPM with `ab` (`docs/performance.md`).
3. **Lazy loading is refused outside production** (`Model::preventLazyLoading`). Production keeps lazy loading so a
   missed relation degrades instead of failing.
4. **Lists load their relations per list, never per row**: eager loading (tasks, calendar, order page), batch
   helpers where a value is derived (`DueCalendar::datesFor`, `StockItems::preload`, `Order::primeAccessDepartments`,
   `PortalPresenter::orders`), and the portal's «required from you» computes money only for orders that accept a proof.
5. **Request-level caches with explicit invalidation.**
   - `App\Support\RequestCache` (scoped: rebuilt per request and per queued job, flushed after every handled request
     and whenever a Facility, Membership or User is saved/deleted, and by `SessionRevoker`): facility time zone, task
     eligibility facts.
   - The Gate (`Authorizer`) re-reads `users.access_version` at most every 2 s within one request instead of on every
     check; this amends ADR-0005's "read on every check". The cache is flushed after every handled request and by
     SessionRevoker: a revocation in this process applies at the next check, one made by another process at the next
     request (or within 2 s in a long-running command). Grants, scopes and the evaluation order are unchanged. No
     cross-request cache (Redis) was added: no hot path needed one.
6. **Payload.** The shared translations (~330 KB) are an Inertia once-prop keyed by locale and language-file version:
   sent on the first visit, a locale switch or a deploy, then kept by the client (staff and portal, separate keys).
   Large list props are passed as row objects (`PropRows`), which Inertia serializes without walking each value.
7. **Indexes** (additive migration `2027_03_01_100000_add_performance_indexes`): `orders (status, id)`,
   `orders (branch_id, status, id)`, `money_entries (order_due_id, order_id, amount_minor)`. The calendar window adds
   a lower bound on `starts_at` (appointments never exceed 72 h, enforced by M-16 T00/T03) so its index reads a range.
8. **Correctness found while measuring.** `OpenDues` bounded the first 500 orders that had *any* due, so at volume the
   finance work list, collection report and reminders saw paid-up history instead of what is owed; it now bounds orders
   with money still to collect. The calendar branch filter ran after the 500-row limit; it is now part of the query.
9. **Guards** (`tests/Feature/Performance`): fixed query budgets for the main staff, portal and store pages; the same
   pages with ~100 more rows must run the same queries (an N+1 fails); lazy loading refused; on MariaDB, EXPLAIN shows
   the key list queries can use their intended index.

## Not done (and why)

- **Keyset pagination**: offset pages stay — page 900 of 20,000 orders is ~60 ms; the cost is the scoped COUNT, not
  the offset. Revisit beyond ~200,000 orders per facility.
- **Queue offloading**: no slow side effect sits on a measured read path; mail/SMS already go through the outbox.
- **Fewer per-row Gate checks** (e.g. SQL scopes instead of `Access::can` per task): after the version cache a check
  costs microseconds; rewriting authorization for speed was out of scope.

## Consequences

- New list code must eager-load (tests fail otherwise) and should use `PropRows` for lists over ~100 rows.
- A budget in `PerformanceGuardsTest` is raised only with a reason in `docs/performance.md`.
- Server tuning (PHP-FPM, OPcache, MariaDB, Redis) are proposals for the server admin; no infrastructure file changed.
