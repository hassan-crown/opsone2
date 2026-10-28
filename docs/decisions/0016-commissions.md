# ADR-0016: Sale ownership and commissions (P6d)

- Status: accepted (2026-10-28) — Q-37 a decided (owner/commission correction screens in v1, as a financial decision
  with a reason). **The commission basis (Q-61) is a reversible default that needs product-owner confirmation.**
- Scope: domain 05 «العمولة» (SRS-SAL-10..16, SAL-19, INT-06), machine M-33 (commission payout), permissions
  P-94..P-98, process P06, mandatory integration scenarios 10, 11 and 12 (commission parts).
- Builds on: ADR-0013 (`Money`, `Rounding` — commission rounded at accrual, Q-34 a; agreement versions,
  `AgreementApplied`), ADR-0014 (append-only ledger, allocation, `CollectionConditions`).
- Numbering note: ADR-0015 is left to 6c (refunds, corrections, disputes), built in parallel.

## Decision

### Sale ownership (SAL-11, SAL-12, SAL-19)

- `order_sales`: one **active** sale per made-to-order order (`active_key` unique), pinned when the order's **first
  agreement is approved** (listener on `AgreementApplied`, idempotent): the member following the order — its
  `assignee`, else its creator — never «whoever edited last»; the rate that applies to that member at that moment;
  and the eligible-line policy of the day.
- Rate resolution (`CommissionRates`): member rate → highest rate of the member's role templates → facility default;
  commissions off → `0` with source `disabled` (SAL-10: off and 0% until set). A pinned sale never changes: owner and
  rate are immutable on the row (model guard). Policy, role or member rate changes apply to sales pinned afterwards
  (SAL-19); disabling a member deletes nothing.
- Ready orders and basket links (SAL-11 «رابط سلة صالح», SAL-17/18) belong to the store (P7/P8): not pinned here.

### Commission basis (SAL-12, INT-06) — Q-61 default, to confirm

```
eligible  = Σ net_minor of the active agreement's lines marked commissionable (after the document discount,
            BEFORE VAT); hand-typed lines count only when the pinned policy allows them
collected = Σ money of the order allocated to its dues (MoneyLedger::allocatedByDue), capped at the agreed value
target    = eligible × rate_bp × collected / (10000 × agreed)      — rounded half-up once (Rounding::divide)
```

- Scenario 10 says «products 1,000 and total 1,200» without saying what the 200 is. We read SAL-12 literally («بعد
  الخصومات وقبل الضرائب والرسوم»): **eligible lines only, before VAT** — a fee line is marked non-commissionable in the
  quote (6a's `commissionable` flag) and VAT never counts. Both readings of the 200 (a 200 fee line with 0% VAT, or
  1,150 inclusive = 1,000 net + 50 fee at 15%) give the document's 25 (tests). 6a's `OrderAgreement::productsValue()`
  stays «as entered» (gross when prices are inclusive) and is **not** used for commission.
- Changing the basis later is cheap: the accrual is derived, so a different formula is one release that re-derives
  every open sale and writes the differences (no rewrite of past entries).
- Unallocated money (a deposit before the agreement, an overpayment held as credit) and a proof under review earn
  nothing (SAL-12, P06 «لا عمولة على زيادة غير مخصصة»). A deposit earns when the agreement allocates it — no new
  payment needed (scenario 10).

### Accrual = signed differences (SAL-13, M-33-T05)

- `commission_entries` is append-only (model guard): each entry is `target − accrued so far` with the derivation
  (eligible, collected, agreed, rate) and the source (`order_collection:{payment}`, `agreement:{id}`,
  `sale_correction:{id}`, or whatever 6c passes). Cumulative recompute means: partial collections sum **exactly** to
  the commission of the whole (one rounding per accrual of the cumulative target), and a repeated event writes
  nothing (idempotent without a dedupe table).
- `CommissionAccrual::recompute()` runs inside the money write's transaction under the order lock. It is called by
  `CollectionConditions::evaluate()` — which 6b calls after every ledger change and 6c is contracted to call after
  corrections and refunds — and by the agreement listener. A refund or correction therefore lowers the accrual by a
  negative difference automatically.
- A negative difference after a payout is carried on the member's statement; nothing paid is erased and no salary is
  deducted (scenario 12).

### Holds (INT-06, P06)

- `SettlementHolds` (interface): an order whose settlement, refund, cancellation or dispute is open is **held from
  payout** — only that order; the accrual is still recorded and the member's other orders stay payable. Default
  `LedgerSettlementHolds`: money on the order above the active agreement (a lowered agreement or an overpayment
  awaiting a refund/transfer). **6c decorates it** with pending refunds and open disputes (`bindIf`).
- A payout reserved before a hold appeared keeps its lines (no double subtraction); the next payout re-derives.

### Payout M-33 (SAL-14)

- `commission_payouts` (one member, one period) + `commission_payout_lines` (sale → amount, or a member-level
  adjustment line). Machine: `draft` →T01→ `reserved` →T02→ `paid` →T04→ `corrected`; `draft|reserved` →T03→
  `cancelled`. T01+T02 may run as one action when the proof is attached (as the machine allows).
- T01 (P-96) locks the **member** row, then the member's orders (id order), derives the balance again and holds every
  payable line at its amount; the total must be positive (`nothing_available`). One open payout per member
  (`payout_open`), so two payouts can never take the same line.
- T02 records money that **left outside OpsOne**: method (`transfer|cash|cheque|payroll`), date, reference, proof
  file (private attachment). It is never refused for a balance that moved after the reservation — the difference is
  carried (P06 «تزامن صرف مع رد»).
- T03 releases the lines with a reason. T04 (P-97) records what really left with a reason and proof; the original
  stays and the difference becomes a member-level adjustment taken by the next payout.
- Every write: idempotency key (`FinanceWrite`, scope `finance.commission.*`; a double submit replays), payout
  `lock_version` (409 `stale_version`), `state_transitions` (M-33) and `audit_events` (area `commissions`) in the same
  transaction. A refused transition is `409 {condition: not_from_<state>}` without a side effect.
- Nobody records a payout to themselves (`own_payout`) — a default safeguard, not in the matrix.

### Corrections (SAL-16, Q-37 a)

- `sale_corrections`: `owner` (request needs P-98) or `rate` (request needs P-97) with a reason; one open per order.
  Approval or rejection is a financial decision (P-97 over the sale) by someone who neither requested it
  (`own_request`) nor is one of the affected members (`own_sale`); nobody requests a correction that moves commission
  to or from themselves («لا يغير المبيعات نسبته لنفسه»).
- Approval supersedes the sale with a new row (same pinning date and agreement), moves its accrual with a
  `transfer_out` / `transfer_in` pair and re-derives the new sale — so the total never doubles and a paid previous
  owner carries the difference.

### Visibility (SAL-15)

- `OrderSale` is a ScopedRecord whose `own` is the **sale's member**; other scopes follow the order. Lists use
  `SaleScope` (mirrors the resolver). A sales person (P-94 `own`) sees only their own statement; a branch manager only
  sales of orders in their branches. A payout's scope is the member's membership (branches/department).
- Screens: `FinCommissions` (`finance.commissions`), member statement = `FinMyCommission` + `FinPayout`
  (`finance.commissions.member`, «كشف عمولاتي» via `finance.commissions.mine`), the order's sale page with the
  correction requests (`orders.sale`), and the commission part of `SettingsSales` (P-95).

## Consequences

- 6c must call `CollectionConditions::evaluate()` (or `CommissionAccrual::recompute()`) after a refund/correction under
  the order lock, and decorate `SettlementHolds` for pending refunds and disputes (contract in
  `docs/p6-finance-api.md` «6d»).
- Lists derive balances on read (bounded by one member's sales); revisit with a cached snapshot for a very large
  sales team.
- Not here: ready-order sales and basket-link attribution (P7/P8), salary integration (none by design), an export of
  statements (reports, P9).
