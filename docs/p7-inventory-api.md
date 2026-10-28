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

## Delivery (P7c, ADR-0018) — M-14

Quantities are decimal text in JSON, thousandths in PHP. Every write takes `idempotency_key` (field or `Idempotency-Key`
header, 8–100 chars, reused on retry) and the note's `lock_version`; errors as above (403 Gate, 409 state / stale, 422).

| Method | URL | Body | Permission |
|---|---|---|---|
| GET | `/deliveries` (`?status&q`) | — | P-52 → `{awaiting: [{id, number, kind, status, customer, lines, partial, ready}], notes: Page<row>}` |
| GET | `/orders/{order}/deliveries/create` | — | P-52 on the order → `{order, lines: [{order_line_id, name, unit, ordered, delivered, released, deliverable, unit_price_minor?}]}` |
| POST | `/orders/{order}/deliveries` | `idempotency_key, note?, lines[{order_line_id, quantity}]` | P-52 on the order → 201 `{note}` (draft, no number) |
| GET | `/deliveries/{note}` | — | P-52 on the order → `{note: {id, number, status, order, lines[{…, quantity, deliverable?, value_minor?}], proofs[{uuid, name, url}], can{confirm, cancel}, lock_version}}` |
| POST | `/deliveries/{note}/confirm` | multipart: `idempotency_key, lock_version, delivered_on (≤ today), receiver_name, note?, proofs[1..5]` | P-52 → `{note}` with `number = DN-YYYY-000123` |
| POST | `/deliveries/{note}/cancel` | `reason, lock_version?` | P-52, drafts only |

PHP seams:

| Seam | Use |
|---|---|
| `Actions\StateMachines\M14Delivery\T00CreateDraft::handle($actor, $order, [{order_line_id, quantity_milli}], ?$note, ?$key)` | draft |
| `…\T01ConfirmDelivery::handle($actor, $note, {delivered_on, receiver_name, note?}, UploadedFile[], ?$lockVersion, ?$key)` | record: order lock → issue reservations → `DN-` number → proof → order T08 → P5 result |
| `…\T02CancelDraft::handle($actor, $note, $reason, ?$lockVersion, ?$key)` | withdraw a draft |
| `Delivery\DeliveryProgress::lines($order)` / `::complete($order)` / `::deliveredByLine($orderId)` | owed per line `{ordered, delivered, released, deliverable}`; completion; the delivered base **for returns** (confirmed notes, thousandths by order line id) |
| `Delivery\DeliveryAccess::{canRecord, canView, canSeeAmounts, orders, scoped}` | P-52 on the order, P-37 for amounts |
| event `App\Delivery\DeliveryConfirmed(orderId, noteId, first, complete)` | after commit; listeners idempotent on `noteId` |

Refusals: draft/confirm on an order that cannot accept a delivery → 409 `order_{status}`; confirming a non-draft → 409
`not_from_{status}`; quantity above deliverable → 422 `lines.N.quantity` / `lines`; no receiver / no proof → 422.

Contracts to other domains:

- **P5**: a complete delivery of a custom order calls `ActionResults::verifyFromSource($order, ActionType::RecordDelivery, null, 'delivery_note', $noteId, $actor)`
  once (nothing for a partial delivery, nothing for ready orders — they have no flow).
- **P6**: due event `delivery` (`DueEvent::Delivery`, schedule rows accept `event: "delivery"`): due on the date of the
  order's first confirmed delivery note; `DueCalendar::dates()` derives it, nothing is stored.
- **P7a**: stock leaves only through `IssueReadyReservation` (movement `issue_reserved`, `source_type = delivery_note`,
  `source_id = note id`, key `delivery:{note}:{line}:{reservation}`).
- **Numbers**: `DN-YYYY-000123` (gapless per year, taken at recording; drafts and withdrawn drafts have none).

## Cancellation and the settlement follow-up (P7e, ADR-0020) — M-34

Every write takes `idempotency_key` (field or `Idempotency-Key` header), the order's `lock_version` and, when applying,
the reviewed `hash`. Errors: 403 Gate, 409 `{condition}` (`preview_stale`, `request_pending`, `fully_delivered`,
`not_from_*`) or `{code: stale_version}`, 422 fields (`lines.N.quantity`, `retained`, `channel`, `reason`, …).

| Method | URL | Body | Permission |
|---|---|---|---|
| GET | `/orders/{order}/cancellation` | — | P-18, or P-16 when a settlement follow-up exists → Inertia `orders/cancel` / JSON `{order, amounts_visible, lines[], pending, history[], settlement, channels, can{review, amounts}}` |
| POST | `/orders/{order}/cancellation/preview` | `scope full\|partial, lines[{ref, quantity}]?, retained?` | P-18 (+ P-37 for `retained`) → `{impact, hash, lock_version}` |
| POST | `/orders/{order}/cancellation/requests` | `+ source, channel, requested_on, reason` | P-18 → 201, custom order `cancellation_pending` (M-34-T01 + M-04-T04) |
| POST | `/orders/{order}/cancellation` | `+ request fields, hash` | P-18 → 201 record and accept (M-34-T03; decides the pending request if any) |
| POST | `/cancellations/{c}/accept` | `scope, lines?, retained?, hash` | P-18 (M-34-T02) |
| POST | `/cancellations/{c}/reject` | `reason, lock_version?` | P-18 (M-34-T04 + M-04-T06) |
| POST | `/settlement-items/{item}/resolve` | `reason, status done\|dropped, lock_version?` | P-43 refund · P-46 supplier · P-18 other |

Line refs: `l{order_line_id}` (ready lines; custom lines before an agreement — whole line only) or `a{index}` (agreement
line of a custom order). `impact`: `order{status, status_before, status_after}`, `lines[{ref, description, unit,
quantity_milli, value_minor?}]`, `stops{tasks[], appointments[], stages[], change}`, `stays{delivered[], issued[],
stages[], collected_minor?}`, `settlement{money{collected_minor, value_before_minor, value_after_minor, retained_minor,
pending_refunds_minor, refund_minor}?, agreement{version_after, total_minor?, dues[{reference, name, amount_after_minor?}]},
stock[{reservation_id, number, item, location, release_milli}], purchases[{id, number, status, supplier, lines[{remaining_milli,
gross_minor (P-82)}]}], commission{user, difference_minor?}}`. Without P-37 every `*_minor` key is absent.

PHP seams:

| Seam | Use |
|---|---|
| `Actions\StateMachines\M34Cancellation\T01RequestCancellation::handle($actor, $order, $data, $lockVersion, $key)` | record a request |
| `…\T02AcceptCancellation::handle($actor, $cancellation, $scope, $hash, $lockVersion, $key)` / `…\T03RecordAndAcceptCancellation::handle($actor, $order, $data, $hash, $lockVersion, $key)` | apply (CancellationApply, one transaction) |
| `…\T04RejectCancellation::handle($actor, $cancellation, $reason, $lockVersion)` | reject |
| `Cancellation\CancellationImpact::compute($order, CancellationInput, ?$pending)` | the review (`hash`) |
| `Cancellation\CancellableLines::for($order)` / `::cancelledByLine($orderId)` | what may be cancelled; quantities taken off custom lines |
| `Settlement\SettlementFollowUps` | **one follow-up per order** — `open($order)`, `add($order, $kind, $source, $details, $targetMinor, $refType, $refId, $done)` (idempotent per `source:kind:ref`), `sync($order)` (items from their sources, close when none open), `resolve($actor, $item, done\|dropped, $note, $lockVersion)`, `forOrder($order)`; all under the order lock |
| `Models\Settlement\SettlementItem::{REFUND, STOCK_RELEASE, SUPPLIER, COMMISSION, RETURN}` | item kinds; a return adds one `return` item with source `return:{id}` (P-42 to resolve otherwise), done by its executed refund |

Numbers: `CN-YYYY-000123` (cancellation request). Events reused: `Finance\Settlement\RefundExecuted` → follow-up sync.
Commission holds: `cancellation` while a refund item is open.
## Returns (P7d, ADR-0019) — M-15

Ready orders only. Quantities are decimal text in JSON, thousandths in PHP; amounts in halalas and present only with
P-37. Every write takes `idempotency_key` (field or `Idempotency-Key` header) and the return's `lock_version`; errors as
above (403 Gate, 409 `{condition}` / `{code: stale_version}`, 422).

| Method | URL | Body | Permission |
|---|---|---|---|
| GET | `/returns` (`?status&q`) | — | P-53 / P-102 / P-42 → `{eligible: [{id, number, status, customer, lines}], returns: Page<row>, settings{window_days, can_edit}}` |
| GET | `/orders/{order}/returns/create` | — | P-53 or P-42 on the order → `{order, lines: [{order_line_id, name, unit, delivered, held, eligible, delivered_on, overdue}], window_days}` |
| POST | `/orders/{order}/returns` | `reason, lines[{order_line_id, quantity}]` | P-53 or P-42 → 201 `{return}` (`RT-YYYY-000123`) |
| GET | `/returns/{return}` | — | any of P-53 / P-102 / P-42 on the order → `{return: {…, lines[], parts[], settlement?, refund?, payments[], locations[], can{accept, reject, withdraw, receive, inspect, refund}}}` |
| POST | `/returns/{return}/accept` | `reason, settlement?, lines?[{order_line_id, quantity}]` | P-102 (T05; 409 `over_delivered`) |
| POST | `/returns/{return}/reject` | `reason` | P-102 (T06) |
| POST | `/returns/{return}/withdraw` | `reason` | P-102, or the requester before the decision (T03; 409 `nothing_to_release`) |
| POST | `/returns/{return}/receive` | `location_id, inspect?, note?, lines[{order_line_id, quantity, good?, damaged?, rejected?}]` | P-53 on the order and location (T01/T07 [+T02]; 409 `nothing_expected`) |
| POST | `/returns/{return}/inspect` | `note?, results[{part_id, good, damaged, rejected}]` | P-53 (T02; 409 `already_inspected`) |
| POST | `/returns/{return}/correct` | `part_id, good, reason` | P-53 + P-49 on the location (T04; 409 `not_inspected`, `nothing_changed`) |
| POST | `/returns/{return}/refund` | `payment_id, amount, method, reason` | P-42 (M-12-T00; 409 `no_settlement`, `refund_exists`, `above_settlement`, `not_refundable`) |
| PUT | `/returns/settings` | `window_days` (0–365) | P-02 |

PHP seams: `Returns\ReturnEligibility::lines($order)` (delivered / held / eligible per line), `ReturnStatus::isOpen()`,
`ReturnSettlementHolds::open($orderId)`; contracts `Returns\Contracts\ReturnRefunds` (bound to M-12) and
`Returns\Contracts\SettlementFollowUp` (bound to `Settlement\ReturnSettlementFollowUp`, the order's one follow-up). Stock: `return_in` (good),
`return_damaged` (custody, no balance effect), `return_reclass` (correction out); `source_type = order_return_part`,
document number = RT.
