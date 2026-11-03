# ADR-0040: No-Code phase 2 — configuration releases (draft → publish → pinned per order)

- Status: accepted (2026-11-03) — engineering decision within the approved No-Code analysis (§4.2 «Configuration
  Release», §3.5 pinning, §6 phase 2 item 5). Number 0040 deliberately: 0039 is left to the appointment-requests work
  running in parallel (see the ADR number claims note).
- Scope: the phase-1 business rules (ADR-0037: payment plans, reminder policy, contract/quote clauses, message texts,
  task SLA and holidays, numbering formats; ADR-0038: the purchase-order document) become a **draft** that is tried,
  then published as a numbered, immutable **release**; orders pin the release they were created on; the change log
  between releases; rollback by publishing a copy.
- Related: ADR-0006 (audit), ADR-0012 (flow versions pinned per order — the model this follows), ADR-0028
  (expand migration), ADR-0037, ADR-0038. Out of scope (phase 3): the automation engine (§4.1), payment-plan
  applicability rules, `allow_proceed` limits, export/import between instances (§4.7), four-eyes publishing.

## Context

Phase 1 settings applied the moment they were saved: an owner editing a payment plan, a clause or a message text
changed the live behaviour field by field, with no way to review a set of changes together, try them, or go back.
Only the order flow had versions (`flows` / `flow_versions`, pinned per order). OpsOne is pre-launch (no tenants), so
the semantics can change without a data migration of live facilities.

## Decision

### 1. The phase-1 tables are the draft; a release is a snapshot

- No new draft tables: the existing settings screens and actions (P-104; P-45 for the purchase-order document) keep
  writing their tables, which are now the **draft**. Each page shows a banner (shared prop `configDraft`) saying
  whether the draft differs from the release in force, with a link to «Releases».
- `ConfigSnapshot::capture()` reads the draft as one typed JSON (`schema: 1`) with seven sections: `payment_plans`
  (active plans with their ids), `reminders`, `clauses` (active, with the version each points at), `messages`,
  `task_sla` (`task_sla`, `task_escalate_after_days`, `holidays`), `numbering`, `purchase_document` (layout and email;
  its version number is a save counter and is ignored when comparing).
- `config_releases`: `number` (unique, 1, 2, …), `snapshot`, `snapshot_hash` (sha-256 of the canonical comparable
  snapshot), `flow_version_id` (the published flow at that moment — a reference only), `notes`, `restored_from_id`,
  `published_by`, `published_at`. The model refuses update and delete, like `FlowVersion`.
- Before the first release the application reads `ConfigSnapshot::defaults()`: the built-in rules, exactly what empty
  phase-1 tables meant. So nothing changes for a facility until it publishes.
- Not in a release: WhatsApp template names (they must match what Meta approved and stay immediate), working weekdays
  and reminder hours (calendar facts, `WorkSettings`), the flow (it keeps its own draft/publish, M-23).

### 2. Who reads what (`ConfigReleases`)

| Rules | Read from | Why |
|---|---|---|
| Payment plans (default, choosable plans, lines copied at the agreement) | the order's pinned release | money the order agreed to |
| Contract / quote clauses copied into a new document | the order's pinned release | document content |
| Reminder policy, message texts, task SLA + holidays, number formats, purchase-order document | the current release | communication and operations act on new events (analysis §4.2) |

- `orders.config_release_id` is set in `Order::creating` to the current release and is immutable (added to
  `Order::IMMUTABLE`). An order created before any release has no pin and follows the release in force.
- Existing guarantees stay: plan lines are still copied into `order_dues` at the first agreement (ADR-0037) and a
  sent/signed document keeps its copied clauses; the pin decides **which** rules are copied.
- Choosing a plan for an order (P-38) offers only the plans of the order's release.

### 3. Publish, try, discard

- `PublishConfigRelease::publish(actor, notes, base, draft_hash)` (P-104): under a row lock on the latest release,
  refuses a stale `base` (another release was published) or a `draft_hash` that no longer matches the draft (it changed
  after the review) with 409 `stale_version`; refuses an unchanged draft; runs `ConfigCheck`; writes release N+1;
  audits `config_release.published` with the per-section change counts and notes.
- `ConfigCheck` re-checks what may have moved since each draft row was saved and what ties sections together: every
  plan valid, its `stage.started` stages in the published flow, the flow's `due` gate references present, exactly one
  default plan, ≤ 20 plans; clause and message placeholders; number prefixes unique and not reserved, a changed
  starting number above its counter. Errors are keyed `section.item`.
- «Try it» (`POST settings/releases/preview`, P-104 or P-02): the draft against the release in force on a sample
  agreed value — every plan's dues to the halala (`OrderSchedule::preview`, `rest` absorbs rounding), the default
  quote/contract clauses rendered with sample values, every changed message text rendered with its event's samples.
  Pure: no write, no outbox.
- Discard puts the draft back to the release in force (`ConfigRestorer`), audited `config_release.draft_discarded`.

### 4. Change log and rollback

- `ConfigDiff::between(a, b)`: per section, items added / removed / changed (plans and clauses by key, messages by
  event·channel·language, numbering by sequence) with readable before/after per field. Shown for the pending draft,
  for each release against the previous one, and for «what a rollback would change».
- Rollback (`rollback(actor, release, notes, base)`, P-104) **publishes a copy**: the draft tables are restored from
  the older snapshot, then published as release N+1 with `restored_from_id`; no release is changed or deleted. Rows
  are never deleted where something points at them: plans/clauses missing from the snapshot are archived; a clause
  returns to the version the snapshot names (its immutable versions stay, and the next text edit takes the next free
  version number); the purchase-order document gets a new version with the old content. Audited
  `config_release.rolled_back` with the source release.

### 5. Access and audit

No new permission code (P-103/P-104 are taken, P-105+ may be taken in parallel): drafting stays where it was (P-104;
P-45 for the purchase-order document), publish/rollback/discard need **P-104**, reading the releases page needs P-104
or P-02. A support identity never writes (ADR-0008). Releases are facility-wide: one instance per tenant (ADR-0002),
so there is no tenant column; isolation is the deployment's.

### 6. Migration

`2027_04_20_100000_create_config_releases` is `#[Expand]`: a new table and a nullable `orders.config_release_id`.
Pre-launch, so no back-fill: existing local/demo orders have no pin and follow the release in force.

## Consequences

- An owner changes several rules, tries them, and publishes them together with notes; running orders keep the money
  and document rules they started with; a bad release is undone in one step without losing history.
- Saving a setting is no longer enough: tests and seeders publish (`publishRules()` in the rules test helpers; the
  showcase publishes release 1 in its foundation, release 2 on 2026-08-02, and leaves an unpublished draft).
- Pages: `settings/releases` (draft, try it, publish, history) and `settings/releases/{id}` (change log, pinned
  orders, rollback). Tests: `tests/Feature/Rules/ConfigReleasesTest.php`.
- Later (phase 3): the automation engine's rules become another release section; applicability rules for plans;
  export/import of a snapshot between instances (the snapshot format is already keyed by stable keys); optional
  four-eyes publishing.
