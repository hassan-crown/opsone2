# P5 workflow engine — service API for the screens

Backend of P5 wave 1 (flow, stages, actions, tasks, appointments, measurements, work settings). There are **no routes
or pages yet**: the three screen sessions add controllers + Form Requests that call these classes. Decisions:
[ADR-0012](decisions/0012-workflow-engine.md).

## Conventions (every write)

- Resolve with the container: `app(T04CompleteStage::class)->handle($user, ...)`. Each Action authorizes **on the
  record** with the central Gate, locks the order row first, re-reads the record, checks the machine and writes the
  change + `state_transitions` (+ `audit_events` for human decisions) in one transaction. Controllers stay thin.
- Errors to map in controllers (most already render themselves for JSON requests):

| Exception | HTTP | Body |
|---|---|---|
| `Illuminate\Auth\Access\AuthorizationException` | 403 | Gate refusal |
| `Illuminate\Validation\ValidationException` | 422 | `errors` |
| `App\Workflow\FlowCheckFailed` (extends the above) | 422 | `errors` keyed by issue path + `issues[]` |
| `App\Access\Exceptions\InvalidTransition` | 409 | `{message, condition}` (e.g. `not_from_assigned`, `order_paused`, `held_by_rework`) |
| `App\Workflow\RequirementsOpen` (M-06-T04) | 409 | `{condition: "requirements_open", open: [action keys]}` |
| `App\Workflow\AppointmentConflict` (M-16-T02/T04) | 409 | `{condition: "conflict_blocked" \| "conflict_reason_required", conflicts: [{id, starts_at, ends_at, member_ids}]}` |
| `App\Support\StaleRecord` | 409 | `{code: "stale_version", lock_version}` |

- `lock_version`: orders (`orders.lock_version`, bumped by every workflow change), flows, tasks and appointments carry
  one. Pass the version the screen read; `null` skips the check (system callers only).
- `source` defaults to `web`. Reasons are required where noted (1–1000 chars, trimmed).
- Names are `{ar, en}`; read models return the current locale.

## Flow settings (M-23) — `FlowEdit`, `FlowCheck`, `FlowStage1-5`, `FlowAddAction`, `FlowAction_*`, `WizardFlow`

| Call | Permission | Notes |
|---|---|---|
| `Flow::current()` + `->publishedVersion->definition` | P-20 to view the editor | The published definition (`{stages: [...]}`); `draft` holds an unpublished edit; `status` = `published` \| `published_with_draft`. `FlowProvisioner::ensure()` creates the default flow if missing. |
| `ActionType::catalogue()` | — | Ten actions: `type, group, completion, label{ar,en}, inputs (rules), defaults`. Groups: `info_measurement, agreement_approval, execution_supply, finance, delivery`. |
| `FlowTemplates::keys()`, `::label($k)`, `::definition($k)` | — | `default` (5 stages), `compact` (3). |
| `app(FlowCheck::class)->run(array $definition)` | P-21 screen | Returns `FlowCheckResult {issues[], stages[]}`; `passes()`. Issue: `{code, path, stage, action, message}`. Safe to call on every edit for live feedback. |
| `T02SaveDraft::handle($user, array $definition, ?int $lockVersion)` | P-20 | M-23-T02. Any shape is stored; checked only when published. |
| `T03PublishFlow::handle($user, ?int $lockVersion)` | P-21 | M-23-T03. Runs FlowCheck → `FlowCheckFailed` or a new immutable version. New orders only. |
| `T04DiscardDraft::handle($user, ?int $lockVersion)` | P-20 | M-23-T04. |
| `T01ChooseTemplate::handle($user, string $template, bool $discardDraft = false, ?int $lockVersion)` | P-21 (+P-20 to discard) | M-23-T01. With an open draft → 409 `draft_open` unless `discardDraft: true` (confirm in the UI). |

Definition shape (what the editor sends):

```json
{"stages": [{
  "key": "measure", "name": {"ar": "...", "en": "..."}, "public_name": {"ar": "...", "en": "..."},
  "show_to_customer": true, "follow_up": {"type": "order_assignee"} | {"type": "member", "id": 12} | {"type": "department", "id": 3},
  "target_days": 3,
  "actions": [{
    "key": "measure", "type": "record_measurements", "name": {"ar": "...", "en": "..."},
    "requirement": "required" | "optional", "applies_when": "always" | "needs_measurement" | "needs_design",
    "assign": {"type": "order_assignee"} | {"type": "member", "id": 12} | {"type": "department", "id": 3},
    "inputs": {"purpose": "final", "measurement_kind": "final"}, "after": null | "other_action_key"
  }]
}]}
```

Inputs per type: `record_measurements {purpose: initial|final, measurement_kind: estimated|final}`,
`prepare_design {requires_attachment}`, `prepare_document {document: quote|contract}`,
`customer_approval {subject: design|quote|contract}`, `work_task {label{ar,en}, requires_attachment}`,
`supply_follow_up {reference}`, `payment_check {reference, basis: amount|percent|remaining, value}` (amount in minor
units; percent 1–100; remaining: null), `required_document {document_type, review_required}`; `capture_needs` and
`record_delivery` have none. Keys: `^[a-z0-9_]{1,40}$`, unique across the flow.

## Order progress (M-06, M-27, M-04) — `OrderStages`, `OrderCompleteStage`, `OrderPause`, `OrderRework`, `OrderEditUpcoming`

| Call | Permission (record) | Notes |
|---|---|---|
| `WorkflowPresenter::order($order, $viewer)` | caller checks P-16 | `null` for orders without a flow. Returns `flow_version, paused, pause, active_stage_id, lock_version, can{pause, edit_upcoming, finish_execution}, blockers[{action, name, type, status, held, responsible_user_id}], stages[{id, key, position, name, public_name, show_to_customer, status, follow_up, target_days, due_on, started_at, completed_at, can_complete, actions[{id, key, type, group, completion, name, requirement, applies_when, status, held, allowed_reason, rework_count, has_source, tasks[...], can{allow, rework}}]}]`. No money. |
| `T04CompleteStage::handle($user, OrderStage $stage, ?int $orderVersion)` | P-25 on the stage (owner, manager, supervisor in scope — Q-30 a) | M-06-T04 → next stage M-06-T01, or M-04-T03 after the last. `RequirementsOpen` lists what blocks. |
| `T04AllowProceed::handle($user, OrderAction $action, string $reason, ?int $orderVersion)` | P-35 on the action (owner/manager) | M-27-T04 for a required ready/awaiting action. Truth unchanged. |
| `T02RequestResult::handle($user, OrderAction $action, string $awaiting, ?int $orderVersion)` | P-24 on the action | M-27-T02; the stage shows «waiting» (M-06-T02). |
| `T05ReworkAction::handle($user, OrderAction $action, string $reason, ?int $orderVersion)` | P-27 on the action | M-27-T05+T06; reopens/creates work, holds dependents, may set M-06-T05 and M-04-T08 (closing correction). |
| `T08MarkNotRequired::handle($user, OrderAction $action, string $reason, ?int $orderVersion)` | P-28 on the order | Only when the need no longer applies and no work started. |
| need change | P-17 via `PUT /orders/{order}` (`needs_measurement`, `needs_design`) | Calls `T07ApplyNeedChange` automatically. |
| `T01PauseOrder::handle($user, $order, string $reason, int $followUpUserId, ?CarbonInterface $reviewOn, ?int $orderVersion)` | P-26 on the order | M-04-T01; follower must be active and see the order. |
| `T02ResumeOrder::handle($user, $order, ?string $note, ?int $orderVersion)` | P-26 | M-04-T02; re-checks the stage. |
| `T03FinishExecution::handle($user, $order, ?int $orderVersion)` | P-25 on the order | After a closing correction (otherwise automatic). |
| `EditUpcomingWork::handle($user, $order, array $upcoming, string $reason, int $orderVersion)` | P-28 on the order | `$upcoming` = the full list of not-started stages (definition shape). `OrderDefinition::fixedStages($order)` gives the current shape to edit. FlowCheck errors are prefixed `upcoming.`. |

## Tasks (M-07) — `TaskList`, `TaskAssign`, `TaskPage`, `TaskWaiting`, `ExecHomeMobile`, `TaskMobile`

| Call | Permission (on the task) | Notes |
|---|---|---|
| `TaskQueries::mine($user)` / `::inbox($user)` / `::followed($user)` | P-24 per row | Open tasks, max 200, with `order` and `action`. Show only the task's fields (WF-08). |
| `TaskQueries::eligibleFor($actor, $task)` | P-22 or P-23 | `[{user_id, name, open_tasks}]` sorted by load. |
| `T01AssignTask::handle($user, $task, ?int $assigneeId, ?int $departmentId, ?int $lockVersion)` | P-22 | Assign an unassigned task, or route it to a department inbox (`assigneeId` null). |
| `T10ClaimTask::handle($user, $task)` | P-24 | Take from own department inbox; atomic. |
| `T02StartTask` / `T05CompleteTask::handle($user, $task, ?string $resultNote, ?int $lockVersion)` | P-24 | Completion checks required attachment (`$task->attachments()`, upload via `AttachmentService` with the task as owner) and measurements. |
| `T03WaitForNeed::handle($user, $task, string $need, CarbonInterface $followUpOn, ?int $lockVersion)` / `T04ResolveNeed` | P-24 | |
| `T06ReassignTask::handle($user, $task, int $assigneeId, string $reason, ?int $lockVersion)` | P-23 | |
| `T07CancelTask::handle($user, $task, string $reason, ?int $lockVersion)` | P-22 or P-28 | Refused (`required_result`) for the only open work of a required unmet action. |
| member disable | P-06 (`T01DisableMembership`) | Releases open work automatically (M-07-T09). |

## Appointments (M-16) — `CalWeek`, daily view, `ApptSchedule`, `ApptChange`

| Call | Permission | Notes |
|---|---|---|
| `AppointmentQueries::between($user, $from, $to, ?array $memberIds)` | P-54 or booked member (P-24) per row | UTC times; show in `AppointmentBooking::timezone()`. |
| `T00ProposeAppointment::handle($user, $order, array $data)` | P-16 on the order **and** P-54 on the order's appointment (an `own` P-54 alone would pass on any order) | `data: kind (measurement\|installation\|delivery\|visit), starts_at, ends_at? (default one slot), address, latitude?, longitude?, member_ids[1..10], task_id?` → `{appointment, conflicts: [ids]}` (warning only). |
| `T01SendToCustomer::handle($user, $appointment, ?int $lockVersion)` | P-54 | |
| `T02ConfirmAppointment::handle($user, $appointment, string $method, ?string $conflictReason, ?int $lockVersion)` | P-54 | `method`: `phone\|in_person\|message\|other`. Atomic conflict check (`AppointmentConflict`). |
| `T03RequestChange::handle($user, $appointment, $startsAt, $endsAt, string $reason, ?int $lockVersion)` / `->discard(...)` | P-54 | Original kept; `{appointment, conflicts}`. |
| `T04ApproveChange::handle($user, $appointment, ?string $conflictReason, ?int $lockVersion)` | P-54 | Same conflict policy. |
| `T05CompleteVisit::handle($user, $appointment, string $outcome, bool $noShow, ?int $lockVersion)` | P-54 or a booked member with P-24 | No-show keeps `confirmed`. |
| `T06CancelAppointment::handle($user, $appointment, string $reason, ?int $lockVersion)` | P-54 | Recomputes conflict markers. |
| `$appointment->mapsUrl()` | — | Q-33 (a) link. |

## Measurements and settings

| Call | Permission | Notes |
|---|---|---|
| `RecordMeasurement::handle($user, $order, array $data, ?Task $task, ?OrderMeasurement $correcting)` | P-14 on the order, or through the assigned open measurement task (which may correct only measurements of its own action's tasks) | `data: order_line_id?, location, name, value (≤3 decimals), unit (mm\|cm\|m\|in\|m2\|pcs), kind (estimated\|final), measured_on (≤ today), note?`; rules in `RecordMeasurement::rules()`. |
| `WorkSettings::current()` | — | Defaults until saved. |
| `UpdateWorkSettings::handle($user, array $data)` | P-02 | `working_days[1..7], work_starts_at, work_ends_at, appointment_slot_minutes (15..240 set), reminder_from, reminder_until, conflicting_appointments (allow_with_reason\|block)`; rules in `UpdateWorkSettings::rules()`. |

## For P6/P7 (result owners)

`app(ActionResults::class)->verifyFromSource($order, ActionType $type, ?string $purpose, string $sourceType, int|string $sourceId, ?User $actor)`
— call inside or after your own transaction once the fact is final (a source taken back by a rework is ignored if replayed) (approved document, confirmed collection, accepted
receipt, recorded full delivery). `$purpose` is the action's `reference` / `subject` / `document`. Idempotent; returns
the matched `OrderAction` or `null` when the order's flow has no such action.
