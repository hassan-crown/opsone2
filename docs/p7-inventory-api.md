# P7 inventory API (P7a) — for delivery, returns, cancellation and procurement

Contract of the stock domain (ADR-0017). Quantities are integers in **thousandths** of the item's unit in PHP
(`App\Inventory\Quantity::parse('1.5') === 1500`) and decimal text in JSON (`"1.5"`). Every PHP write below must run
inside the caller's transaction, started with `App\Support\LockingTransaction::run()`; lock the **order row first**
(`App\Inventory\OrderStock::lockOrder($orderId, $lockVersion)`) when the write concerns an order.

## Service seams (PHP)

| Seam | Use | Authorization |
|---|---|---|
| `Inventory\Contracts\StockReceiver::receive($locationId, $itemType, $itemId, $quantityMilli, $sourceType, $sourceId, $actorId, $idempotencyKey)` | supplier receipt (P7b) | caller (P-47 on the location) |
| `Actions\Inventory\IssueReadyReservation::handle($actor, $order, $reservation, $quantityMilli, $sourceType, $sourceId, $idempotencyKey)` | stock side of a ready delivery (M-05-T08) | P-52 on the order (inside) |
| `Actions\StateMachines\M05ReadyOrder\T09CancelReadyRemainder::handle($actor, $order, $reason, $lockVersion)` | cancel the undelivered part of a ready order | P-18 (inside) |
| `Actions\StateMachines\M24Reservation\T02ReleaseReservation::handle($reservation, $actor, $reason)` | release one reservation's remainder (CancelReview of a custom order) | caller |
| `Actions\StateMachines\M24Reservation\T01IssueReservation::handle($reservation, $quantityMilli, $actor, $refs)` | low-level issue; prefer `IssueReadyReservation` | caller |
| `Inventory\OrderStock::reservations($order)` / `readyLines($order)` | what is held / issued / released per order and line (impact review, delivery screen) | read |
| `Inventory\OrderStock::transferImpact($order, $branchId)` | branch transfer impact | read |
| `Inventory\StockItems::unitFor($itemType, $itemId)` | the fixed unit an item is counted in (null = cannot be tracked yet) | read |
| `Inventory\StockAccess::locations($user, Permission ...)` | locations a grant covers (lists, pickers) | read |

Item types: `ready_variant` (catalog_ready_variants.id, unit `piece`) | `material` (catalog_materials.id with
`track_quantity`). Unknown type → `InvalidArgumentException`; missing/descriptive/unknown unit → `ValidationException`.

Semantics to rely on:

- `receive()` is a no-op on a replayed key (checked after the balance row lock). Keys are unique across the whole
  journal: namespace them (`po-receipt:{receiptId}:{lineId}`, `delivery:{deliveryId}:{lineId}`).
- `IssueReadyReservation` refuses (`InvalidTransition`, 409 `not_from_…`) when the order is not `confirmed` or
  `partially_delivered`, and a quantity above the reservation's remainder (422 `quantity`). It does **not** change the
  order status; the delivery action sets `partially_delivered` / `delivered` from the effective quantity.
- `T09CancelReadyRemainder` releases only unissued remainders (`released` or `settled_mixed`), never returns what was
  issued, and sets the order `cancelled` (nothing issued) or `delivered`. A cancellation and a delivery on the same
  order serialize on the order row: whichever commits first wins; the other re-reads (scenario 4).
- Returns (P-53): accepted-but-not-received quantities never touch stock; post the inspected good quantity as a
  receipt-like movement with a return source after inspection (FUL-06/FUL-11). Damaged stays out of available.

## HTTP endpoints (`routes/inventory.php`, JSON)

Errors: 403 Gate, 404 wrong record, 409 `{condition}` state / `{code: stale_version}` version, 422 field errors.

| Method | URL | Body | Permission |
|---|---|---|---|
| GET | `/stock` (`?location_id&q&low`) | — | P-48 / P-49 / P-50 (balances; `available_only` for sales/supervisor) |
| GET | `/stock/movements` (`?location_id&kind&item_id`) | — | P-48 + stock grant on the location |
| GET | `/stock/ready` | — | P-48 |
| GET | `/stock/items` (`?q`) | — | any stock grant (picker of trackable items) |
| PUT | `/stock/items` | `item_type, item_id, unit?, reorder_level?` | P-49 |
| PUT | `/stock/settings` | `units[], low_stock_alerts` | P-02 |
| POST | `/stock/adjustments` | `location_id, item_type, item_id, direction(in/out), quantity, reason` | P-49 on location |
| GET/POST/PUT | `/stock/locations`, `/stock/locations/{id}` | `code, name{ar,en}, branch_id?, status?, manager_id?, lock_version?` | P-49 on branch |
| GET/POST | `/stock/transfers` | `from_location_id, to_location_id, lines[{item_type,item_id,quantity}], instant?, note?` | P-50 on both |
| POST | `/stock/transfers/{id}/dispatch` · `/receive` · `/instant` · `/cancel` | `lock_version?` | P-50 on both |
| GET/POST | `/stock/counts`, `/stock/counts/{id}` | `location_id, reason, lines[{item_type,item_id,counted}]` | history on location |
| PUT | `/stock/counts/{id}` | `lines[...]` (draft) | history on location |
| POST | `/stock/counts/{id}/submit` · `/approve` (`note?`) · `/reject` (`note`) | `lock_version?` | submit: history; decide: P-49 |
| POST | `/orders/{order}/confirm` | `location_id?, lock_version?` | P-15 + `ready_goods` (M-05-T01) |
| POST | `/orders/{order}/cancel-remainder` | `reason, lock_version?` | P-18 (M-05-T09) |
| GET | `/orders/{order}/materials` | — | P-51 on order, or view + P-48 |
| POST | `/orders/{order}/materials/reserve` | `location_id, material_id, quantity, lock_version?` | P-51 on order and location |
| POST | `/orders/{order}/materials/issue` | `+ reservation_id?, idempotency_key` | P-51 on order and location |
| POST | `/orders/{order}/materials/{reservation}/release` | `reason` | P-51 |

Confirm / cancel response: `{order: {id, status, lock_version}, stock: ReadyLineStock[]}` with per line
`quantity, available, short, reserved, issued, released, reservations[]`.

The order page (`orders/show`) receives `stock`: `{kind: 'ready', lines, locations, can: {confirm, cancel}}` or
`{kind: 'custom', open_reservations, can: {materials}}`.

Branch transfer impact `reserved_stock`: `{available: true, applicable, reserved_lines: Reservation[] (+ stays_outside_target), outside_target}`.

## Document numbers

`SR-YYYY-000001` reservation · `SA-` adjustment · `SI-` direct material issue · `ST-` transfer · `SC-` count; each
movement carries its document number, so a reservation's reserve → issue → release rows share `SR-…`.
