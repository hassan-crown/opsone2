# P4 orders backend — endpoint contract

Backend for the order screens (`CreateOrder*`, `OrdersList*`, `OrderOverview`, `OrderBranch`, `OrderStates`).
The screens are **not** built yet; the screens session wraps these endpoints in Inertia pages (or calls them with
`fetch`/Inertia `router` + `Accept: application/json`). Decisions: [ADR-0011](decisions/0011-orders.md).

- All routes live in `src/routes/orders.php`, behind `auth` + `verified`, under the web session (CSRF token required
  for writes). Wayfinder generates `@/actions/App/Http/Controllers/Orders/*` — use those, never hand-written URLs.
- Every endpoint authorizes **on the record** through the central Gate (`Access`); lists use `ScopedQuery`, so a list
  never shows what a direct open would refuse. Hidden UI is never the control.
- Responses are JSON. Errors: `403` (Gate: no grant, out of scope, record state, or license), `404`, `409`
  (stale `lock_version` → `{code: "stale_version", lock_version}`; invalid transition → `{condition}`),
  `422` (validation, `{errors: {field: [...]}}`).
- Money is integer **minor units** (halalas). An unknown price is `null`, never `0`.

## Financial fields (P-37)

Without **P-37 over the order** the payload simply does not contain money: `total_minor`, `unit_price_minor`,
`line_total_minor`, and `price_minor` / `reference_price_minor` inside a line's `snapshot.item` are removed, and
`financials_hidden: true` is set. With P-37 the keys are present and `financials_hidden: false`. The screen should
render a «hidden» placeholder when `financials_hidden` is true — never `0`.

## Endpoints

| Method & path | Name | Permission | Purpose |
|---|---|---|---|
| `GET /orders` | `orders.index` | P-16 (scoped list) | Paginated list (20) |
| `GET /orders/options` | `orders.options` | P-15 | Data for the create screen |
| `POST /orders` | `orders.store` | P-15 (+`ready_goods` for the ready part) | Open custom / ready / both |
| `GET /orders/{order}` | `orders.show` | P-16 over the order | Order overview |
| `PUT /orders/{order}` | `orders.update` | P-17 over the order | Edit need / flags / invoice type / assignee / contact |
| `POST /orders/{order}/lines` | `orders.lines.store` | P-17 (+`ready_goods` on ready orders) | Add a frozen line |
| `DELETE /orders/{order}/lines/{line}` | `orders.lines.destroy` | P-17 | Remove a line (kept in history) |
| `GET /orders/{order}/branch-transfer?branch_id=` | `orders.branch-transfer.preview` | P-19 over both branches | Impact review |
| `POST /orders/{order}/branch-transfer` | `orders.branch-transfer.apply` | P-19 over both branches | Atomic transfer |
| `GET /customers/{customer}/branch-transfer?branch_id=` | `customers.branch-transfer.preview` | P-10 over both branches | Impact review |
| `POST /customers/{customer}/branch-transfer` | `customers.branch-transfer.apply` | P-10 over both branches | Atomic transfer |

`POST /orders` uses its own rate limiter (`orders-create`, 30/min per user).

### `GET /orders`

Query: `q` (number prefix or customer name), `kind` (`custom|ready`), `status`, `branch`, `assignee` (id or `none`),
`customer`, `page`.

```json
{
  "orders": [OrderRow],
  "meta": {"page": 1, "last_page": 3, "total": 47, "per_page": 20, "from": 1, "to": 20}
}
```

`OrderRow`:

```json
{
  "id": 12, "number": "ORD-2026-00012", "kind": "custom", "status": "in_progress",
  "customer": {"id": 3, "name": "..."}, "branch": {"id": 1, "name": "..."},
  "assignee": {"id": 7, "name": "..."} | null, "purchase_reference": "PR-2026-00004" | null,
  "created_at": "ISO-8601", "financials_hidden": false, "total_minor": 9000 | null
}
```

### `GET /orders/options`

```json
{
  "branches": {"custom": [{"id": 1, "name": "..."}], "ready": [...]},
  "members": [{"id": 7, "name": "..."}],
  "invoice_types": ["tax", "simplified"]
}
```

`branches.ready` is empty when the license lacks `ready_goods` — hide the ready section then (the server refuses it
anyway).

### `POST /orders`

The client generates **one idempotency key per create screen** (e.g. `crypto.randomUUID()`) and resends the same key
on retry/double-click. Field `idempotency_key` or header `Idempotency-Key` (8–100 chars `[A-Za-z0-9_\-:.]`).

```json
{
  "idempotency_key": "6f1c...",
  "customer_id": 3, "branch_id": 1,
  "assignee_id": 7,                 // optional, defaults to the actor; must be an active member with P-16 over the order
  "contact_id": 5,                  // optional; organization customers only; defaults to the primary contact
  "purchase_reference_id": 4,       // optional: join an existing reference of the same customer
  "custom": {                       // optional (at least one of custom / ready)
    "need_summary": "required, max 2000",
    "needs_measurement": true, "needs_design": false,
    "invoice_type": "tax|simplified|null",
    "lines": [                      // optional, max 50
      {"type": "custom_need", "description": "...", "quantity": "1", "unit": "m"},
      {"type": "custom_product", "product_id": 9, "quantity": "3.5", "unit": "m",
       "options": [{"key": "color", "value": "White"}]}
    ]
  },
  "ready": {
    "invoice_type": null,
    "lines": [{"type": "ready_variant", "variant_id": 4, "quantity": 2}]   // 1..100, whole quantities
  }
}
```

- `custom` + `ready` together ⇒ two orders sharing a **new purchase reference** (`PR-YYYY-nnnnn`). The reference is not
  an order and has no amount.
- Custom order ⇒ M-04 `in_progress`, `total_minor: null`, lines without price. Ready ⇒ M-05 `draft`, line prices
  frozen from the variant's current version, `total_minor` = sum of active lines.
- Response `201` `{orders: [OrderDetail...], purchase_reference_id, replayed: false}`; a replay of the same key and
  payload returns `200` with `replayed: true` and the same orders. Same key + different payload ⇒ `422` on
  `idempotency_key`. Field errors of a part are prefixed (`custom.lines.0.product_id`, `custom.assignee_id`, …).

### `GET /orders/{order}` → `{order: OrderDetail}`

`OrderDetail` = `OrderRow` plus:

```json
{
  "lock_version": 3,
  "customer_id": 3,
  "contact": {"id": 5, "name": "...", "mobile": "+9665..."} | null,
  "need_summary": "...", "needs_measurement": true, "needs_design": false, "invoice_type": "tax" | null,
  "related_orders": [{"id": 13, "number": "ORD-2026-00013", "kind": "ready"}],
  "lines": [{
    "id": 1, "position": 1, "type": "custom_product|custom_need|ready_variant",
    "catalog_version_id": 22,
    "snapshot": {"version": 2, "item": {...catalog version payload...}, "options": [{"key", "label", "value"}]},
    "description": null, "quantity": "3.500", "unit": "m", "removed": false,
    "unit_price_minor": 4500, "line_total_minor": 9000          // only with P-37
  }],
  "can": {"edit": true, "transfer_branch": false}
}
```

`related_orders` lists only siblings (same purchase reference) the actor may view.

### `PUT /orders/{order}`

`{lock_version (required), need_summary?, needs_measurement?, needs_design?, invoice_type?, assignee_id?, contact_id?}`.
Only keys present change. Ready orders ignore the need fields. Editable states: M-04 `in_progress|paused`, M-05
`draft`; otherwise `403` (record state). Returns `{order: OrderDetail}` with the bumped `lock_version`.

### Lines

- `POST /orders/{order}/lines` — `{lock_version, type, product_id?|variant_id?, quantity?, unit?, description?, options?}`
  ⇒ `201 {order}`. The line kind must match the order kind.
- `DELETE /orders/{order}/lines/{line}` — body `{lock_version}` ⇒ `{order}`. The line stays with `removed: true`.
- Lines are **never edited**: to change one, remove it and add a new one.

### Branch transfer (Q-29 option a)

1. `GET .../branch-transfer?branch_id=` ⇒ `{impact, lock_version}` (orders) / `{impact}` (customers):

```json
{
  "from_branch": {"id": 1, "name": "..."}, "to_branch": {"id": 2, "name": "..."},
  "assignee": {"current": {"id": 7, "name": "..."} | null, "keeps_access": false, "requires_new": true},
  "open_lines": 2,                                            // orders
  "orders_staying": 3, "open_interests": 1,                   // customers
  "appointments": {"available": false, "upcoming": null},     // filled by P5
  "reserved_stock": {"available": false, "reserved_lines": null} | {"available": false, "applicable": false}  // P7, orders
}
```

2. `POST .../branch-transfer` — `{branch_id, reason (3..1000, required), assignee_id?, lock_version (orders only)}`.
   When `assignee.requires_new` is true an `assignee_id` valid in the target branch is required (orders; a customer
   may become unassigned). Applied in one transaction with an audit event (reason, before/after, impact) visible to
   both branches. Response `{impact, order: OrderDetail|null}` (`null` when the actor can no longer see the order) /
   `{impact, customer: {id, branch_id}}`.

## Permissions recap (default roles)

| Role | P-15 | P-16 | P-17 | P-19 | P-37 |
|---|---|---|---|---|---|
| Owner | tenant | tenant | tenant | tenant | tenant |
| Manager | branches | branches | branches | branches | branches |
| Supervisor | — | department¹ | department¹ | — | — |
| Sales | branches | branches | branches | — | branches |
| Finance | — | branches | — | — | branches |
| Inventory | — | branches | — | — | — (money hidden) |

¹ Orders have no department until the workflow (P5); a department grant covers no order yet.
