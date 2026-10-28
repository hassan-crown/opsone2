# ADR-0017: Inventory — locations, movements, balances and reservations (P7a)

- Status: accepted (2026-10-28) — Q-43 (a) applied: a fixed list of enable-able units, no automatic conversion.
- Scope: domain 06 «مخزون بسيط» (SRS-FUL-01..04, the stock side of FUL-02/09/12, INT-01), machines M-24 (stock
  reservation) and M-25 (stock transfer), M-05-T01 (confirm with reservation) and M-05-T09 (cancel the undelivered
  part), permissions P-48..P-52 (P-45..P-47 and P-53 are scoped on the same location record), scenarios 1, 3, 4 and 6
  (stock parts). Contract for delivery, returns and cancellation: `docs/p7-inventory-api.md`.
- Builds on: ADR-0005 (Gate, ScopedRecord), ADR-0006 (audit inside the transaction), ADR-0011 (orders, gapless numbers,
  `LockingTransaction`, branch transfer impact).
- Parallel: P7b (suppliers, purchase orders, receiving) receives through the shared `StockReceiver` interface.

## Decision

### Quantities and units (Q-43 a)

- Every quantity is an integer in **thousandths** of the item's unit (`*_milli`, `App\Inventory\Quantity`: string
  parsing, up to 3 decimals, never a float).
- `App\Inventory\StockUnit` is a fixed list (piece, meter, m², linear meter, sheet, roll, kg, litre, box, set, pair).
  The facility enables a subset (`inventory_settings.enabled_units`, P-02); `piece` is always on, a unit in use cannot
  be disabled.
- A tracked item (`stock_items`) has exactly **one** unit: ready variants are always `piece`; a material is tracked only
  when the catalog says `track_quantity` and its unit is a known fixed unit (matched from the material's free-text unit
  label in ar/en, or chosen once on the item settings dialog). The unit is frozen at the first movement. Descriptive
  materials never get a stock row (FUL-04).

### Journal and balances

- `stock_movements` is **append-only** (model guards refuse update and delete): kind, location, item, unit, positive
  quantity, signed `on_hand_delta` / `reserved_delta`, balance after, document number, references (reservation,
  transfer, count, order, free `source_type`/`source_id`), reason, actor, time, optional unique `idempotency_key`.
- `stock_balances` (location × item) is the projection written in the **same transaction** as each movement.
  Invariant (tested after every scenario): `on_hand = Σ on_hand_delta`, `reserved = Σ reserved_delta`,
  `0 ≤ reserved ≤ on_hand`; available = on hand − reserved.
- `App\Inventory\StockLedger` is the single write path: `lock()` creates the balance row at zero if missing
  (`insertOrIgnore`) and re-reads it `SELECT … FOR UPDATE`; `lockMany()` locks several rows in one global order
  (location, item) so writers never deadlock on each other; `post()` refuses a non-positive quantity and any result
  that would be negative or take reserved stock. Callers start their transaction with `LockingTransaction::run()`
  (READ COMMITTED on MariaDB, deadlock retry).
- Kinds: receipt, issue, reserve, release, issue_reserved, transfer_out, transfer_in, adjustment_in/out. A wrong
  movement is answered by an opposite one (adjustment), never edited.
- Document numbers (gapless per year, `GaplessSequence`): reservations `SR-YYYY-000001`, adjustments `SA-`, direct
  material issues `SI-`, transfers `ST-`, counts `SC-`.

### Locations and scope

- `stock_locations`: code (stored upper-case, unique), bilingual name, optional branch, status, person in charge.
  The migration creates one facility-wide default location `MAIN` (FUL «موقع افتراضي واحد»).
- A location is a `ScopedRecord` on its branch; a location **without a branch is covered by facility grants only**.
  Transfers need P-50 over both ends; reservations are scoped on the location and the order branches.
- Locations are managed with P-49 over the location's branch (facility-wide ones with a facility grant). A location
  holding stock, and the default location, cannot be disabled.
- P-48 matrix note «منحة ر3 ور4 تتيح المتاح فقط دون الحركات»: the balances screen shows **available only** unless the
  viewer also holds a stock-operating grant (P-46/P-47/P-49/P-50) on the location; the same rule opens the movement
  history and counts (`StockAccess::canSeeHistory`). Interpretation: the matrix's «منحة أخرى صريحة» is any of those
  stock grants; an explicit extra P-48 grant alone does not widen it (to confirm with the product owner).

### Reservations (M-24) and ready orders (M-05)

- `stock_reservations`: location, item, order (and line for ready orders), purpose (`ready_order`/`custom_order`),
  quantity, issued, released, status `active → partially_issued → issued`, `active → released` (T02),
  `partially_issued → settled_mixed` (T03). Remaining = quantity − issued − released.
- **M-05-T01** (P-15 + `ready_goods`): locks the order row first, then every balance row (global order), checks each
  line (variant still selectable, the sum per variant available) and reserves each active line from **one** location —
  one of the order's branch or a facility-wide one (`OrderStock::locationsFor`) — in the same transaction. A short
  line refuses the whole confirmation (FUL-02); a draft or cart never reserves. Two confirmations of the last unit:
  one wins, the other gets 422 (scenario 3, race-tested).
- **M-05-T09** (P-18, reason): under the order lock, releases every open reservation's unissued remainder; issued
  quantities stay (no return to stock without a return). Status `cancelled` when nothing was issued, else `delivered`
  (the effective quantity). The «issued» total is re-read after the row locks.
- Delivery (M-05-T08, later session) calls `IssueReadyReservation` under the order lock (P-52, idempotent per key);
  it never changes the order status itself. Cancellation racing a delivery keeps exactly one outcome (scenario 4,
  race-tested).
- Branch transfer (COM-12): the P4 placeholder is replaced by real data — open reservations stay at their locations
  and do not move with the order; the impact lists them and counts those at another branch's location.

### Custom orders' materials (P-51, FUL-03)

- Screen `orders/{order}/materials` (the gap noted in the screen inventory): reserve, issue from a reservation or
  directly from what is available (`SI-`), release the unissued remainder. Needs P-51 on the order **and** on the
  location; refused for ready orders, final orders and (for new reserve/issue) `cancellation_pending` (INT-01).
  Issues are idempotent per client key. Nothing is ever computed from dimensions.

### Transfers (M-25), adjustments and counts

- Transfer: draft (T00) → exit (T01, `transfer_out` at the source; the quantity is *in transit* and shown as
  «incoming» at the destination but not available there) → arrival once (T02, `transfer_in`); withdrawal before exit
  (T03, no movement); instant (T04, both in one transaction). Return after exit = a new linked transfer.
- Adjustment (P-49): direction, quantity and a required reason; never below reserved.
- Count: whoever may see the location's history counts and submits; a P-49 holder on the location approves — each
  difference against the on-hand **at approval time** is posted as an adjustment linked to the count — or rejects
  with a note. Approval below what is reserved is refused (release first). Nothing changes before approval; the same
  person may count and approve (SRS «لا يفرض موظف ثان على من يجمع الصلاحيات»).

### Alerts

- Optional reorder level per tracked item (`reorder_level_milli`): when the item's available quantity across active
  locations falls below it, P-49 holders get one in-app notification per day (`stock.low`, email per preference),
  sent after commit. It never buys anything.

### Audit and transitions

- Every write records an audit event inside its transaction (area `stock`; ready confirm/cancel in `orders`) and
  M-24 / M-25 / M-05 transitions in `state_transitions`.

## Consequences

- Procurement (P7b) only calls `StockReceiver::receive()`; its key must be namespaced (`po-receipt:{id}:{line}`)
  because `idempotency_key` is unique across the journal.
- Returns (P-53) will add a `return_in` style receipt only after inspection; the ledger needs no change (use
  `receive()`-like posting with a return source, or add a kind).
- No stock costing, batches/serials, recipes or automatic consumption (domain 06 «خارج الإصدار»).
- Open: the r3/r4 «available only» interpretation above; per-location reorder levels (today per item).
