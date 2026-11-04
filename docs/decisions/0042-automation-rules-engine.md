# ADR-0042: No-Code phase 3 — automation rules engine («عند … وإذا … فافعل …»)

- Status: accepted (2026-11-04) — engineering decision within the approved No-Code analysis (§4.1 rules/automation
  engine, §4.2 draft/try/publish, §4.4 limits). Number 0042 deliberately: 0041 is claimed by the field-sales work running
  in parallel (see the ADR number claims note).
- Scope: a facility builds rules «when [event] and if [conditions] then do [actions]» without code; the rules are a
  section of the configuration release (ADR-0040); execution on the queue with idempotency, loop protection, a per-rule
  execution log, audit and a live kill switch; settings pages (list, builder, «try it», log) with five ready templates.
- Related: ADR-0002 (one instance per tenant), ADR-0005 (access), ADR-0006 (audit), ADR-0007 (outbox), ADR-0012
  (state machines, tasks), ADR-0030 (WhatsApp), ADR-0037 (message texts, task SLA in working days), ADR-0040 (releases).

## Context

Phase 1 made the rules that differ between retailers editable; phase 2 made them a draft that is tried and published
as a pinned release. What a facility still cannot express is «react to this event»: remind a customer three days
before a due, alert a manager about an untouched lead, tell the customer an installation is booked, open a follow-up
task when a payment is a week late. OpsOne is pre-launch (no tenants), so the engine can be added without migrating
live data.

## Decision

### 1. Triggers reuse what is already recorded

A closed catalogue in code (`App\Automation\AutomationTriggers`), each trigger fed by a fact the application already
writes — never by arbitrary model events:

| Source | Triggers |
|---|---|
| `state_transitions` (machine + code) | `order.stage_started` (M-06-T01), `order.stage_completed` (M-06-T04), `quote.sent` (M-08-T01, quotes), `quote.expired` (M-08-T05), `document.approved` (M-08-T02: approved or signed), `appointment.scheduled` (M-16-T00), `appointment.confirmed` (M-16-T02), `lead.created` (M-03-T00), `lead.assigned` (M-03-T01), `task.created` (M-07-T00) |
| `audit_events` (changes that are not a machine) | `appointment_request.created`, `installation.remark_opened`, `installation.remark_resolved` |
| Timers (`automation:tick`, every 15 min, inside the reminder hours) | `payment.due_soon` (N days before), `payment.overdue` (N days late, after grace), `lead.stale` (open lead untouched N hours), `task.sla_breached` (N hours past `due_at`) |

`AutomationServiceProvider` hooks the `created` event of `StateTransition` and `AuditEvent`. Both rows are written
inside the change's transaction, so the `automation_events` row commits or rolls back with the change, and the job is
dispatched after commit. Only triggers some release listens to are recorded (`config_releases.automation_triggers`,
set at publish), so a facility without rules writes nothing. `dedupe_key` makes each source fact one event; a timer's
key is trigger + parameter + subject + anchor (due date, the lead's last touch, the task's due time), so an hourly scan
raises each fact once. Field sales (ADR-0041) adds its events by adding rows to the catalogue (`lead.*`).

### 2. Conditions are typed comparisons, never code

`{match: all|any, items: [{field, op, value}]}` with at most 10 items. Fields are a closed list per fact group
(`order.total` money in halalas, `order.kind`, `order.stage`, `order.age_days`, `branch`, `customer.kind`,
`customer.has_email`, `customer.whatsapp_opt_out`, `stage.key`, `document.kind`, `appointment.kind`, `lead.channel`,
`lead.status`, `lead.age_hours`, `lead.open`, `request.kind`, `request.source`, `task.hours_late`, `due.amount`,
`due.days_late`, …); a trigger exposes only its groups. The field type fixes the operators (number/money: `= ≠ > ≥ < ≤
between`; enum/branch/stage: `= ≠ in not_in`; bool: yes/no). Values are validated on save against the type and its
closed options (branches, the published flow's stage keys). Facts are read live when the rule is evaluated
(`AutomationSubject`), so a timer re-checks — a due paid meanwhile no longer matches `due.amount > 0`. A missing fact
never satisfies a comparison.

### 3. Actions are a closed catalogue through existing write paths

`send_message` (a customer template of the outbox: WhatsApp when allowed, else SMS, via `CustomerMessenger`; or email
via `Outbox`), `create_task` (a task of kind `automation` on the order's active stage, `due_at` in working days, routed
by `StageAssignment` with `TaskEligibility`), `notify` (in-app + mail through `Notifier` to the responsible person, a
member or the holders of a role **who may open the subject** — role recipients are filtered by the permission the
notification link re-checks), `assign` (a lead via P-09, an order via `OrderAccess::isValidAssignee`, a task via
`TaskEligibility`), `add_note` (a new append-only `order_notes` row, shown on the order's history tab). At most 5 per
rule. Rule texts use only `{order} {customer} {subject} {rule}`. Nothing records money, moves stock, changes a price,
overrides a gate or closes an order. Actions run as «the system on behalf of the rule»: no actor, source `automation`
on transitions and audit (`automation.action_executed` per action).

### 4. Rules are part of the configuration release

`automation_rules` is the draft (P-104); `ConfigSnapshot` gains the section `automation`, so `ConfigDiff`,
`ConfigCheck` (members still active, roles and stages still present, ≤ 100 rules), rollback (`ConfigRestorer` archives
rules missing from the snapshot) and discard work unchanged. An event about an order reads the rules of the release the
order pinned (`ConfigReleases::forOrder`); any other subject (a lead, an appointment request without an order) the
current release. A release published before this section existed reads as «no rules».

**Kill switch**: `automation_rules.killed_at` is live and outside releases: a stopped rule is skipped at once in every
release, including those orders pinned, until revived; both audited with the reason. `enabled` is part of the release
(a disabled rule is published but does not run).

**Try it**: `AutomationSimulator` runs the rule as edited (not saved) against the latest 5 real events of its trigger,
read from the same sources (transitions, audit events, the timer's live subjects), and describes what each action would
do (rendered text, recipients, task and due time). Events are not saved, actions only described, and the run is inside
a transaction rolled back: nothing is sent or written.

### 5. Execution, idempotency, loops, log

- `ProcessAutomationEvent` (Redis queue, after commit): 3 attempts, backoff 10 s / 60 s, timeout 60 s;
  `automation:tick` re-queues events left unprocessed after 10 minutes.
- `automation_runs`: one row per event and rule — `matched`, `skipped` (`killed`, `disabled`, `conditions`,
  `loop_self`, `loop_depth`, `rate_limit`, `subject_missing`) or `failed` (`action_failed`) with the evaluated
  conditions and each action's result. The rule's log page shows the last 100.
- `automation_actions.idempotency_key` = `automation:{rule}:{event}:{index}`, unique, written in the action's own
  transaction: a retried job finishes what is left and never repeats an action; two workers racing on one event cannot
  both act. The outbox and notification keys derive from it as a second guard.
- Loop protection: while an action runs, `AutomationContext` holds the chain of rule keys; an event caused by it records
  `caused_by` and `depth`. A rule never runs on an event its own action caused (`loop_self`), chains stop at depth 3
  (`loop_depth`), and one rule acts at most 5 times a day for one subject (`rate_limit`).

### 6. Access, tenancy, limits

No new permission code (P-105/P-106 may be taken by field sales): editing, killing and publishing need **P-104**,
reading the pages P-104 or P-02 (`RulesAccess`); a support identity never writes (ADR-0008). One deployment per
facility (ADR-0002): no tenant column; within the facility, recipients and assignees are scoped by the subject's branch
through the Gate. Limits: 100 rules, 10 conditions, 5 actions, timer parameters bounded per trigger, 500 subjects per
timer scan, 20 recipients per notice.

### 7. Migration

`2027_05_10_100000_create_automation_tables` is `#[Expand]`: new tables `automation_rules`, `automation_events`,
`automation_runs`, `automation_actions`, `order_notes`, and a nullable `config_releases.automation_triggers`.

## Consequences

- Owners write «three days before a due, remind the customer» or «a lead untouched 48 h alerts the manager» from
  templates, try it on real recent events, publish it with the other rules, and stop it in one click if it misbehaves.
- Pages: `settings/automation` (list, templates, kill switch, 7-day counts), `settings/automation/new|{id}/edit`
  (builder + try), `settings/automation/log/{key}`. Tests: `tests/Feature/Automation/*`.
- Deferred: delays inside a rule («wait 2 days then …», analysis §4.1 timers), backtests over archived events, nested
  condition groups, per-customer daily message caps, custom fields in conditions (phase 3 builders), a purge policy for
  old `automation_events` / `automation_runs`, events from field sales beyond `lead.created/assigned/stale`.
