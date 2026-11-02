# ADR-0037: No-Code phase 1a — payment plans, reminders, clauses, messages, task SLA, numbering

- Status: accepted (2026-11-02) — engineering decision within the approved No-Code analysis (phase 1, items 1, 2, 4,
  5, 6 and 7). Item 3 (facility tax data) is covered by the ZATCA seller tax profile (ADR-0033); item 8 (retail
  workflow template, workshop purchase orders, stage roles) is phase 1b, built separately.
- Scope: owner-managed payment plans copied into the order at the agreement, one source for the first-payment share,
  the collection reminder policy and the appointment lead time, the contract/quote clause library with versions,
  editable message texts and per-facility WhatsApp template names, task SLA in working days with reminder and
  escalation, document numbering formats. Owner pages, Arabic first (RTL).
- Related: ADR-0005 (access), ADR-0006 (audit), ADR-0007 (outbox), ADR-0012 (workflow), ADR-0013/0014 (money,
  schedule, collections), ADR-0028 (expand/contract migrations), ADR-0029 (documents), ADR-0030 (WhatsApp),
  ADR-0033 (ZATCA). Analysis: `docs/analysis/no-code-configurability.md` (PR 21).

## Context

The rules that differ most between retailers and showrooms — how the price is split into payments and when each
falls due, how customers are reminded, contract wording, message texts, task deadlines and document numbers — were
fixed in code. Worse, the first-payment share lived twice: in the flow's `payment_check` (`percent 50`) and in the
schedule template (`OrderSchedule::TEMPLATES['half']`, 5000 bp), joined only by the reference `first` (analysis §0.2).
There are no tenants yet (pre-launch), so no back-compat migration of live data is needed; dev, demo and showcase
seeders must keep working.

## Decision

### 1. Payment plans (typed settings, no rule engine)

- `payment_plans`: `key`, `name{ar,en}`, `lines` (JSON), `is_default` (exactly one active default), `archived_at`,
  `lock_version`. A line: `{key, name, basis percent|amount|rest, value (bp | halalas | null), event, stage_key,
  offset_days 0..365, grace_days 0..90}`. **No plan versions in phase 1:** the order copies the lines into
  `order_dues` (new columns `stage_key`, `offset_days`, `grace_days`) when its first agreement is approved
  (`OrderSchedule::sync`), and records `order_finances.payment_plan_id/payment_plan_key`. Later edits of the plan never
  reach that order; an agreement change re-prices from the copied lines (as before).
- Plan choice: the order may record a plan before the agreement (`ChoosePaymentPlan`, P-38); otherwise the default
  plan; with no plan at all, the built-in `half` (identical to the old template) — `OrderSchedule::TEMPLATES` is gone,
  `PaymentPlans::TEMPLATES` holds the starting templates (`half`, `single`, `fifty_fifty`, `forty_forty_twenty`). After
  the agreement, choosing a plan replaces the schedule under the existing FinSchedule rules (never above the
  agreement, never below what a due holds).
- Closed trigger catalogue (`DueEvent`): `agreement`, `contract.approved`, `design.approved`, `stage.started` +
  `stage_key`, `delivery` (first), `delivery.completed` (the confirmed note that completed the order, new
  `delivery_notes.completes_order`), `date` (orders only), `payment_stage` (built-in fallback only). A due's date is
  the event's date plus `offset_days`; `grace_days` delays only «overdue» (status and reminders), never the amount.
  Negative offsets («3 days before installation») are phase 2: they need a planned date for the event.
- Validation (`PaymentPlans`): 1..12 lines; unique keys; at most one `rest`, and last; without `rest` only
  percentages making exactly 100% (an amount needs a `rest`); triggers from the catalogue; `stage.started` names a
  stage of the published flow; every reference the published flow's `due` gates wait for exists in the plan; at most
  20 active plans. Money stays integer halalas: the `rest` (or the last share at 100%) absorbs rounding.

### 2. One source for the first payment share

`payment_check` gets the basis `due` (`{reference, basis: due, value: null}`): met when the order's net collection
covers the schedule through the due with that reference (allocation is oldest-first, ADR-0014). The share therefore
comes only from the plan. The flow templates now use `due` for `first` and `final`; `FlowCheck` no longer accepts
`percent` (it is still read on actions already pinned to an order, so nothing pinned breaks). A schedule without the
reference asks for the whole agreed value (safe side), and plans are refused when they lack a flow gate reference.

### 3. Reminder policy

`reminder_settings` (one row; defaults reproduce the old behaviour with a cap): `before_days` (≤ 3), `on_due`,
`after_every_days`, `max_after` (≤ 6), `escalate_after_days` + `escalate_to` (`order_assignee` or a role template
key), `appointment_hours` (the WhatsApp/SMS appointment reminder, formerly a fixed 24 h). `CollectionReminders::step()`
is the pure rule; the outbox key `due:{id}:{soon|today|overdue}:{due_on}:{step}` makes every reminder once-only, the
reminder hours still apply and disputed amounts are still excluded. Escalation is an in-app notice
(`finance.due_escalated`) sent once per due and due date, only to people who may see the order's money (P-37).
Reminder policies are not pinned to orders: communication follows the latest settings.

### 4. Contract and quote clause library

`document_clauses` (current text, `version`, kinds `quote|contract`, «added by default», order) and immutable
`document_clause_versions`. Text may use only `{customer} {order} {facility} {total} {execution_days} {validity_days}
{date}`. When a contract (from the agreement) or quote is prepared, the default clauses are rendered and copied into
`content.clauses` with `content.clause_versions [{key, version}]`, so they enter the content hash; a quote draft
re-renders from its pinned versions when re-priced; a sent or signed document never changes (existing M-08 rules). A
contract without library clauses keeps the built-in v1 clauses. The contract's facility registration and VAT number
now come from the ZATCA seller tax profile (closing the analysis's M4 gap with ADR-0033's data, no second profile).

### 5. Message templates

`message_templates` (event, channel `sms|mail|in_app`, locale, subject, body). `MessageCatalogue` is the closed list:
customer finance texts (SMS and the mail copy), the SMS fallback of every WhatsApp event, and staff in-app notices
(`task.assigned`, `work.reminder`, `work.escalated`, `finance.receipt_recorded`, `finance.due_escalated`), each with
its allowed `{variables}`; any other placeholder is refused on save. Rendering is plain text substitution at send
time (`MessageTemplates`); absent = the built-in translation. A preview renders sample values and writes nothing.
WhatsApp itself sends Meta-approved templates, so only their names are per facility (`integration_settings.whatsapp
.config.templates`, falling back to the environment's `opsone_<event>`).

### 6. Task SLA

`work_settings` gains `holidays`, `task_sla {enabled, default, by_type}` (working days per action type; 3 by default)
and `task_escalate_after_days`. `TaskFactory` fills `tasks.due_at` at creation (end of the Nth working day in the
facility time zone, skipping rest days and holidays — `WorkingDays`); it never moves afterwards. The hourly
`tasks:remind` sends the already-catalogued `work.reminder` to the assignee within a day of the due time and
`work.escalated` once to the follower, else the order's responsible person, else the owner (never the assignee),
inside the reminder hours. SLA is per action type (closed catalogue) rather than per flow action, so phase 1a does not
touch the flow editor.

### 7. Numbering formats

`number_formats` per sequence of a closed list (orders, quotes, contracts, receipts, refunds, …): prefix
(`[A-Z0-9]{1,8}`, unique across sequences and the reserved stock/store/invoice prefixes), year `gregorian|hijri|none`
(Umm al-Qura via `ext-intl`), digits 3..8, a branch segment with one counter per branch (orders only: the caller knows
the branch), and a starting number for the current period (must exceed the last number issued). `GaplessSequence`
keeps one locked counter per name and period, so numbers stay gapless and unique; switching the year mode starts a new
period. **Tax invoices (INV/CRN/DBN) are not in the list**: ZATCA sequencing stays fixed.

### 8. Access and audit

New permission **P-103** «configure business rules» (owner template; any member can be granted it). Reading the pages
needs P-103 or P-02. Every change is audited (`payment_plan.*`, `reminder_settings.updated`, `document_clause.*`,
`message_template.*`, `integrations.whatsapp_templates_saved`, `task_sla.updated`, `number_format.saved`, and
`payment_plan.chosen` on the order). Pages: `settings/payment-plans`, `reminders`, `clauses`, `messages`,
`task-sla`, `numbering`, linked from the settings home («قواعد العمل»).

### 9. Migrations

`2027_04_01_100000_create_no_code_settings` is `#[Expand]`. Widening `order_dues.due_event` to 32 characters is a
`change()`, which ADR-0028 only allows in a `#[Contract]` migration (`…100100_widen_order_dues_due_event`): it keeps
every value valid but runs in the maintenance window like any contract migration.

## Consequences

- One place decides money splits; the flow decides only where work stops. Facilities can model 50/50, 40/40/20
  («before installation») or B2B splits without code.
- Phase 2 builds on this: plan versions pinned per order, applicability rules, negative offsets on planned dates,
  `allow_proceed` limits and the automation engine. The `half` fallback and `payment_stage` stay until then.
- Communication settings apply to new messages only; money settings apply to orders agreed after the change.
- Tests: `tests/Feature/Rules/*` (plans, triggers, copying, money integrity, gate unification, reminders, escalation,
  clauses, messages, WhatsApp names, SLA working days, numbering, authorization).
