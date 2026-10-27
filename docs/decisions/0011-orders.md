# ADR-0011 — Orders: opening, frozen lines, numbering, idempotency, locking, branch transfer (COM-08..12, Q-29)

Status: accepted (Q-29 default (a))

> Numbered 0011 because 0010 is the catalog ADR. Endpoint contract: [`docs/p4-orders-api.md`](../p4-orders-api.md).

## Decision

- **Separate orders, shared reference.** A sale of ready and made-to-order goods is two `orders` rows (`kind` =
  `custom` | `ready`) with their own lines and totals, linked by a `purchase_references` row (`PR-YYYY-nnnnn`). The
  reference has no amount and is never summed or collected (COM-10, COM-D01). A single order may join an existing
  reference of the same customer that the actor can see.
- **States.** `orders.status` holds an M-04 state (custom) or an M-05 state (ready). P4 implements only the creation
  transitions: M-04-T00 «جارٍ» (`in_progress`) and M-05-T00 «مسودة» (`draft`), each one Action
  (`app/Actions/StateMachines/M04CustomOrder`, `M05ReadyOrder`) logging `state_transitions` + audit. The full
  transition tables are declared on `CustomOrderStatus` / `ReadyOrderStatus`; `App\Orders\OrderTransitions::check()`
  refuses undefined or not-from-here transitions before any write (later phases call it from their Actions). The
  record-state step of the Gate (`Order::permitsAction`) applies the matrix conditions: P-17/P-29 only while editable
  (M-04 `in_progress|paused`, M-05 `draft`), P-15 on an existing order only to confirm a ready draft, P-18/P-19 not
  on final states.
- **Custom opens without price** (COM-08): need summary, branch, customer, assignee; lines optional (free-text need or
  catalog product + options); no unit price, `total_minor = NULL` (unknown is not zero). Ready drafts need ≥ 1 line;
  line price = variant price at add time, `total_minor` = sum of active lines.
- **Frozen lines** (COM-11): a line stores `catalog_version_id` (the item's *current* version when added) and a
  `snapshot` (that version's payload + chosen options). Catalog edits write new versions and never reach the line.
  `OrderLine` refuses update (except marking it removed) and delete; a change is remove + add.
- **Ready goods license at record level** (ADR-0005 note 8). New contract `Access\Contracts\LicensedRecord`: the Gate
  asks the record for an extra capability after the permission's own one. `Order` requires `ready_goods` for P-15,
  P-17 and P-29 on ready orders; viewing, cancelling and transferring stay open so existing ready orders can be
  settled after the module is removed (COM-10). Deny reason `license`.
- **Scope.** `Order` implements `ScopedRecord`: branch = `branch_id`, owner = `created_by`, assignee = `assignee_id`,
  department = none until the workflow (P5) — a department grant covers no order yet. Lists use `ScopedQuery` with
  P-16. The assignee must be an active, non-support member with P-16 over the order.
- **Money visibility.** Money on an order (totals, line prices, prices inside snapshots) is sent only to holders of
  **P-37 over the order** («المالية لها قيود مستقلة»); otherwise the keys are absent and `financials_hidden: true`.
- **Gapless numbers** (`ORD-YYYY-nnnnn`, per year; the facility is implicit — one deployment per facility, ADR-0002).
  `sequence_counters(name, period, last_value)`; `App\Support\GaplessSequence::next()` does one atomic upsert
  (`last_value + 1`) that takes the row's exclusive lock, then a locking read, inside the caller's transaction. A
  rollback gives the number back. The same class serves receipts later.
- **MariaDB 11.8 snapshot isolation.** With the default REPEATABLE READ and `innodb_snapshot_isolation=ON`, locking a
  row changed after the transaction's first read fails with error 1020. Measured: 8 concurrent workers numbering after
  a prior read lost 80% of attempts to 1020 (numbers stayed gapless). `App\Support\LockingTransaction` therefore starts
  the numbering transaction at **READ COMMITTED** (that transaction only, MySQL/MariaDB only) and retries concurrency
  errors; measured again: 240/240 committed, 0 errors, numbers 1..240. A first insert-ignore + `FOR UPDATE` design
  also deadlocked (shared → exclusive upgrade) and was replaced by the upsert.
- **Idempotent creation.** `POST /orders` requires an idempotency key (field or `Idempotency-Key` header).
  `App\Support\Idempotency` inserts `idempotency_keys(scope, user_id, key, request_hash)` in the **same** transaction
  as the work: a concurrent duplicate waits on the unique index, then replays the stored result (`replayed: true`);
  a failed first attempt rolls the key back with everything else; the same key with a different payload is refused.
- **Optimistic locking.** `orders.lock_version`; every write sends the version it read. `App\Orders\OrderLock::fresh`
  re-reads the row `FOR UPDATE` as the first statement of the write transaction and throws `StaleRecord` (409,
  `code: stale_version`) on mismatch; each successful write bumps it.
- **Branch transfer (Q-29 (a))**: one atomic action, no intermediate state. GET returns an impact review (assignee
  keeps access or not, open lines, appointments placeholder for P5, reserved stock placeholder for P7); POST requires
  a reason, re-computes the impact under the row lock, requires a valid new assignee when the current one would lose
  access, and writes branch + assignee + version + audit event (reason, before/after, impact; visible to both
  branches) together. Orders: P-19 over the order in its current **and** target branch. Customers: P-10 over both
  (the matrix has no separate customer-transfer permission); the customer's orders keep their own branches.

## Consequences

- Order screens (P4 screens session) consume JSON endpoints; Wayfinder actions exist for every route.
- Confirmation of ready drafts (M-05-T01, stock reservation) waits for inventory (P7); pause/resume/finish/cancel of
  custom orders arrive with the workflow (P5/P6) and must call `OrderTransitions::check()` on the locked row.
- Interest conversion (M-03-T02) still does not open an order (its TODO stays); wiring it should call
  `T00OpenCustomOrder` inside the conversion transaction.
- Other `lockForUpdate()` writers in the app run at REPEATABLE READ; they are safe only while the lock is the first
  read of their transaction (as `OrderLock::fresh` is). New hot counters must use `LockingTransaction`.
