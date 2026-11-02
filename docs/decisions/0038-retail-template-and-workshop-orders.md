# ADR-0038: Retail default flow, workshop purchase orders and stage assignment (No-Code phase 1b)

- Status: accepted (2026-11-02) — engineering decision on the product owner's positioning (retailers and showrooms
  that outsource manufacturing) and explicit requirement (a printable, emailable, customisable purchase order).
- Scope: analysis `docs/analysis/no-code-configurability.md` (PR 21) §0 (2b), §1.4 W10–W13, §1.8, §2.3, §2.7, §4.5,
  §4.6 and §6 phase 1 item 8.
- Related: ADR-0012 (workflow engine; this adds an eleventh catalogue action), ADR-0017-procurement (M-13),
  ADR-0007 (outbox), ADR-0029 (mPDF pipeline), ADR-0006 (audit and private files). Phase 1a (payment plans, reminders,
  clauses, message templates, numbering, SLA) is a separate change and is not touched here.

## Context

The default flow assumed in-house manufacturing (a 21-day `work_task` «manufacturing»), while OpsOne's customer is a
showroom that sends the work to an external workshop. The nearest action, `supply_follow_up`, had no caller of
`verifyFromSource` and could only be completed by «allow» (W11); a purchase order's `expected_on` was linked to nothing
(W12); a stage could be followed by the order's owner, a member or a department, not by a role or per branch (W13).
The product owner also asked for a purchase-order document the facility can print, email from the system and shape
itself. OpsOne is pre-launch with no tenants, so the new default simply replaces the old one.

## Decision

### 1. Templates

- `FlowTemplates::RETAIL = retail_outsourced` is the default: needs and measurement → design and quote → agreement and
  first payment → **sent to the workshop** (`workshop_order` milestone `sent`) → **workshop receipt and quality
  check** (`workshop_order` milestone `received`, then a quality-check task) → installation and delivery. Stage keys
  `measure, design, agreement, workshop, receiving, install`; action keys of the shared stages are unchanged.
- Industry variants on the same skeleton: `retail_kitchens` (an optional second workshop order `countertop` for the
  stone supplier, 18-day receiving stage), `retail_aluminium` (measurement always required before cutting, 10 days),
  `retail_furniture` (30 days, warehouse wording). Payment checks use basis `due` (ADR-0037): the shares (for
  example 30/70 for furniture) come from the facility's payment plan, not from the flow.
- The former default stays as the template `in_house`; `compact` is unchanged.
- **Add-on `addon_in_house_manufacturing`**: a stage pack (materials, fabrication, assembly, workshop QC) the owner
  inserts into a draft from the existing flow editor (`options.addons`, keys renamed when taken), then edits, checks
  and publishes like any change. It is not a separate engine feature: a facility that owns a workshop composes it.
- The provisioned template is per deployment: `opsone.default_flow_template` (`OPSONE_DEFAULT_FLOW_TEMPLATE`), validated
  against the known keys, default `retail_outsourced` — a kitchen tenant can be provisioned with its variant. Every
  template is valid by construction: it names only the order's owner, the stage's responsible, or the `inventory` role
  when that role template exists.

### 2. `workshop_order` (eleventh catalogue action) and the purchase order as its only source

- Inputs `{reference, milestone: sent|received}`; completion `source`; `received` depends on `sent` of the same
  reference and FlowCheck refuses a `received` without it (`receipt_without_workshop_order`).
- `Procurement\WorkshopOrders::sync()` runs inside M-13 T01 (confirm), T02 (receive), T03 (cancel remainder) and T04
  (adjust), for every order the purchase order's lines are linked to:
  - `sent` ← the purchase order is confirmed (a draft never counts) — source `purchase_order`;
  - `received` and `supply_follow_up` ← every matching line fully **accepted** — source `purchase_receipt`. A
    rejected quantity never satisfies it (failed quality check keeps the action open); a cancelled remainder neither.
  - Matching: the purchase order's `flow_reference`, or — without one — the only action of its kind on the order whose
    family agrees with the supplier (`is_workshop` ↔ `workshop_order`). Two references and no `flow_reference` match
    nothing: we never verify by guess.
  - The receiving stage's `expected_on` = the latest expected date of its matching purchase orders; it becomes the
    stage's due date in the progress read model, with `days_late` while the stage is open.
- Results recorded before the stage opens are kept and applied when it opens (existing engine rule).
- This closes W10 (template), W11 (`supply_follow_up` now completes from procurement) and W12 (expected date, late
  alert).

### 3. Workshop purchase orders

- A supplier gets `is_workshop` and `lead_days` (no separate entity: a workshop is a supplier, with its purchase
  history, VAT number and access rules). `lead_days` proposes `expected_on` on create.
- A new purchase line type `order_line` sends lines of the customer's order to the workshop: same order only, not
  removed, frozen name, unit `piece`, **never stocked** (receipt records quantities, no `StockReceiver` call).
- The order page offers «workshop purchase order» on the `sent` action (P-46 in the order's branch), opening the
  purchase form prefilled with the order's custom lines, its branch, the only active workshop and the reference.
- «Orders at workshops» (`/workshops`): open workshop purchase orders (workshop supplier, a `flow_reference` or an
  `order_line` line) with expected date, days late and received progress, filtered by workshop, branch and late only;
  scoped exactly like the purchase list (P-46/P-47); no costs.
- `workshops:late-alerts` (daily 07:30): notification `workshop.late` (link re-checks P-46) to the purchase order's
  responsible, the order's owner and the open workshop stages' responsible, once per purchase order **and expected
  date** (moving the date re-arms it).

### 4. Stage assignment

- Targets for a stage follow-up and an action assignment: `order_assignee`, `member`, `department` (existing), `role`
  (any member of a role template) and `per_branch` (`[{branch_id, user_id}]`); actions may also use `stage` (the
  stage's responsible). The retail templates send every action to its stage's responsible.
- `Workflow\StageAssignment` resolves them: a role picks, among active members who pass `TaskEligibility` for the
  order (P-24 over its branch, department), the one with the fewest open tasks, then the lowest user id; per branch
  takes the order's branch row; when nobody qualifies the order's owner is the fallback. Role and per-branch followers
  are resolved again when the stage starts (who is free now). Tasks are created for the resolved person through the
  existing `TaskFactory` (assigned + notified, or unassigned under a known follower).
- FlowCheck refuses an unknown role, an empty or broken per-branch list (unknown branch, inactive member, duplicate
  branch) and `stage` as a stage follow-up. The order stores the target as defined (`follow_up_target`,
  `assign_target`) so editing upcoming work (P-28) round-trips it.

### 5. The purchase order as a document

- **PDF**: `PurchaseOrderPdf` renders `resources/views/pdf/purchase-order.blade.php` through the ADR-0029 mPDF pipeline
  (IBM Plex Sans Arabic, logo and brand colour of the published theme), Arabic first, English on request.
- **Per-facility layout** (`PurchaseDocumentLayout`): an ordered list of known blocks with visibility (facility,
  branch, supplier, order and customer reference, expected date, delivery address, lines, notes, terms, signatures),
  closed flags (facility contact; customer name and phone — **off by default**, so the workshop does not see the
  customer; line measurements, product options as colours/materials, line notes, design and measurement images;
  prices — off; signature; stamp), the title and terms text in both languages, and the email subject/body with closed
  placeholders `{number} {supplier} {facility} {branch} {expected_on}`. No free HTML, no new data fields.
- **Versions**: every save is a new immutable `purchase_document_versions` row (stale saves refused, unchanged saves
  ignored, audit `purchase.document_saved` with before/after). Each generated PDF is stored once per content
  fingerprint (`purchase_order_documents`: data, images, template version, language, prices) as a private attachment
  of the purchase order and records the **template version** it used; the PDF footer prints it.
- **Prices** appear only when the layout shows them **and** the viewer holds P-82 on the order — a buyer without P-82
  never produces or downloads a priced copy.
- **Email** (`PurchaseOrderDocuments::send`, P-46, confirmed and not cancelled): the facility texts prefilled and
  editable in the dialog, the stored PDF attached, sent through the outbox (kind `purchase_order`: transactional,
  queued after commit, claimed atomically, retried with backoff, never sent twice; the idempotency key stops a double
  click). Each send is logged in `purchase_order_sends` with its recipient, subject, body, language, document and
  sender; its final status (`queued`/`sent`/`failed`) is mirrored from the outbox because delivered outbox rows are
  pruned after 30 days. A failed send (retries exhausted) can be sent again (new row, `retry_of`). Audit
  `purchase.emailed`.
- **Preview**: the settings page renders the unsaved layout as a PDF on the viewer's latest purchase order (or an
  in-memory sample when there is none).

### 6. Permissions (no new permission codes)

To avoid colliding with the parallel phase 1a, existing permissions cover each feature:

| Feature | Permission |
|---|---|
| Templates, add-on, assignments | P-20 edit / P-21 publish (M-23, audited `flow.*`) |
| Workshop flag and lead time | P-45 (supplier profile, audited `supplier.*`) |
| Workshop purchase orders, emailing, retry | P-46 on the purchase order (branch scope) |
| Board, PDF | P-46 or P-47 (scoped); prices in the PDF additionally P-82 |
| Purchase-order document settings and preview | P-45 (facility-wide procurement settings; audited) |

## Consequences

- New facilities start with the retail flow; the test suite's order fixtures pin `in_house` in `tests/TestCase.php`
  (they describe the manufacturing flow), and `tests/Feature/Workshop` covers the retail default end to end. Two
  catalogue-size assertions moved from 10 to 11.
- The showcase (`ShowcaseSeeder`) now runs the retail flow: three workshop suppliers, workshop purchase orders per
  kitchen (most emailed with the PDF), receipts after the lead time with realistic delays, a few rejected on the first
  inspection, and the ones still open at the end of the story late on the board. Workshop draws use their own PRNG
  stream so the rest of the dataset is unchanged.
- Migration `2027_04_01_100000_create_workshop_purchase_tables` is additive (`#[Expand]`).
- Not done here (later phases): auto-advance between stages, SLA in working days for tasks (phase 1a), approvals of
  purchase orders by amount (R1), product-category conditions choosing in-house vs workshop per line (W3/W4), a
  per-category default workshop.
