# ADR-0017: Suppliers, purchase orders and receiving (P7 part B)

- Status: accepted (2026-10-28). Q-43 decided as «a»: fixed units, no conversion, quantities in thousandths.
- Scope: domain 06 «الموردون والتوريد» (SRS-FUL-05..07), machine M-13, permissions P-45, P-46, P-47, P-82, screens
  `Supplier*`, `Purchase*`, `PoCancel`.
- Builds on: ADR-0013 (`Money`, `VatCalculator`), ADR-0006 (private attachments), `GaplessSequence`, `Idempotency`.
- Counterpart: the stock session (P7 part A) owns locations, balances and movements; procurement reaches it only through
  `App\Inventory\Contracts\StockReceiver`.

## Decision

- **Units and quantities (Q-43 a).** A line freezes the item's own unit (a material's `unit`; `piece` for a ready variant)
  and never converts. Quantities are integers in thousandths (`quantity_milli`); money is integer halalas. Screens parse
  decimal text with string arithmetic (`Procurement\Quantity`), never floats.
- **M-13 states.** `draft → ordered («مطلوب») → partially_received → received`, or `cancelled_remainder` from draft, ordered
  or partially received. T00 create, T01 confirm (supplier, lines, location, date and responsible required), T02 receive,
  T03 cancel remainder (reason), T04 adjust ordered quantity (reason; never below what was accepted). Every transition is
  logged in `state_transitions`, every write audited.
- **Remaining = ordered − accepted.** A receipt states what physically arrived as `accepted + rejected`. Only the accepted
  part enters the stock and satisfies the order; the rejected/damaged part is kept with its reason and does not reduce the
  remainder (FUL-06: ordered 10, 4 accepted + 1 rejected → 6 remain). The order is `received` only when every line has no
  remainder.
- **Over-receipt.** An accepted quantity above the remainder is refused. A larger supply is first agreed explicitly with
  T04 (reason, logged), then received — never a silent overshoot.
- **Cancel the remainder keeps what was received.** No stock movement is reversed; a cancelled order accepts no receipt.
- **Receiving into stock.** T02 calls `StockReceiver::receive()` once per line with accepted quantity > 0, inside the same
  transaction, with the key `po-receipt:{receipt_id}:{order_line_id}`. If the call throws, the whole receipt rolls back.
  Materials the facility does not track (`track_quantity = false`) are descriptive (FUL-04) and are not sent to the
  stock. The receive action is idempotent per request key (`ProcurementWrite` over `Idempotency`): a retry replays the
  first result and touches nothing. No production binding of `StockReceiver` exists in this part.
- **Numbering.** `PO-YYYY-000123` from `GaplessSequence('po')` inside the order's `LockingTransaction`; a rolled-back
  creation gives its number back. One deployment is one facility (ADR-0002), so «per facility» is implicit.
- **VAT.** The facility policy (`FinanceSettings`: rate, inclusive/exclusive) is captured on the order at creation and
  again at confirmation; per line, `unit cost × quantity` is rounded half-up once and VAT is computed per line; the order
  total is the sum of the rounded lines (Q-34/Q-35).
- **Costs are a separate permission.** Unit cost, line and order amounts leave the server only for P-82 holders on the
  order; the payload omits the keys entirely. A writer without P-82 cannot set a cost, and a cost already on a line they
  resend is kept.
- **Scope.** A purchase order belongs to one branch (`branch_id`); P-46/P-47/P-82 are checked on it with the central Gate.
  Suppliers are facility-wide (P-45, tenant scope); anyone who may buy or receive may read the supplier list. A supplier is
  disabled, never deleted: its purchases stay, and it cannot be chosen on a new order. VAT number: 15 digits starting and
  ending with 3.
- **Optimistic lock.** `lock_version` on suppliers and orders; a stale write is a 409/`StaleRecord`. Writes serialize on
  the order row (`lockForUpdate`).
- **Locations by id.** `purchase_orders.location_id` and `purchase_receipts.location_id` are plain ids (no foreign key); the
  receive screen takes the order's location. `Procurement\Locations::options()` returns none until the stock side supplies
  a directory; the screens then show a select instead of the id field.

## Not in this part

Purchase need pre-filled from an order's shortage (a `?item_type=&item_id=&quantity=` prefill is accepted by the create
screen), reorder-level alerts, supplier accounts or payments (explicitly out of scope: no payables), and the real
`StockReceiver` binding.
