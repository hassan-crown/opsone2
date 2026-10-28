# ADR-0012 — Workflow engine: fixed actions, one flow, pinned versions, tasks, appointments (P5 wave 1)

Status: accepted (Q-30 a, Q-31 a, Q-32 a, Q-33 a)

> Service contract for the screen sessions: [`docs/p5-engine-api.md`](../p5-engine-api.md). Backend only; no routes
> or pages in this wave.

## Decision

- **Fixed action catalogue in code (D-03, WF-03).** `App\Workflow\Catalogue\ActionType` holds the ten system actions
  of domain 03 with their group (five fixed display groups), completion rule (`task` | `measurement` | `source`),
  input rules, defaults, the input that tells two uses apart (`referenceOf`), and the only links the engine knows
  (`dependsOn`: approval → its document, percentage/remaining collection → an agreement approval, design →
  measurements). A facility only chooses actions, required/optional, a need condition (`always` | `needs_measurement`
  | `needs_design`), routing, an optional «available after» an earlier action, and names. No fields, rules or scripts.
- **One flow per facility (M-23, WF-01).** `flows` has exactly one row (`singleton` unique key). Provisioning (or the
  first order) publishes the default five-stage template (`FlowTemplates`); a second template (`compact`) exists.
  Templates route to the order's responsible person only, so they never need departments a facility lacks. M-23:
  T01 choose template (P-21; refused while a draft is open unless it is discarded explicitly), T02 save draft (P-20),
  T03 publish (P-21), T04 discard (P-20). Each publish writes an immutable `flow_versions` row (model refuses update
  and delete).
- **FlowCheck (WF-04).** `App\Workflow\FlowCheck::run()` returns every issue with `code`, `path`
  (`stages.2.actions.0.type`), stage/action keys and a translated message that says how to fix it. It checks shape,
  keys, names, target days, inputs, follow-up and routing targets (active member/department), license capability of
  each action, approvals without their document at the same or an earlier stage, percentage/remaining collection
  without an agreement approval, required actions waiting on optional/conditional ones, duplicate purposes
  (collection, receipt and delivery are never counted twice), «after» pointing later, and cycles. Publishing throws
  `FlowCheckFailed` (422 with `issues`).
- **Pinned version per order (WF-10).** Opening a custom order (inside M-04-T00's transaction) creates
  `order_workflows` (pinned `flow_version_id`) and copies stages and actions onto the order (`order_stages`,
  `order_actions`, with resolved `depends_on`). Publishing never touches existing rows. Ready orders have no flow.
- **Execution.** `App\Workflow\StageEngine` is «خدمة المسار»: M-06-T01 opens a stage (single active main stage in
  `order_workflows.active_stage_id`), M-27-T01 makes actions ready when their prerequisites are met and creates their
  work once (M-07-T00), M-27-T03 verifies results, M-06-T02/T03 keep the waiting marker. People decide through
  Actions: complete a stage (M-06-T04, P-25 on the stage), allow proceeding (M-27-T04, P-35), rework (M-27-T05 then
  T06, P-27), need changes (T07 via P-17 order update, T08 via P-28), pause/resume (M-04-T01/T02, P-26), finish
  execution (M-04-T03, P-25), edit upcoming work (P-28). Every transition is one Action logging `state_transitions`
  (and `audit_events` for human decisions) inside its transaction.
- **Results from other domains.** Source actions (document, approval, collection, receipt, delivery) are verified
  only by `App\Workflow\ActionResults::verifyFromSource()` called by P6/P7 from their own record. A repeated signal is
  a no-op; a result recorded before its stage opens is stored and applied when the action becomes ready (early
  collection/approval). A task never verifies a source action.
- **Rework and closing correction (WF-08, WF-11).** Rework marks the action `needs_rework`, then makes it ready again
  with known work: the same task reopened (M-07-T08) while its stage is active and its holder still eligible,
  otherwise a linked correction task. Dependent actions not yet done are *held* (`held_by_action_id`) until the rework
  is verified; nothing else reopens and no collection/stock effect is repeated. A completed stage becomes
  `completed_needs_correction` (M-06-T05) without becoming active; a finished or closed order returns to
  `in_progress` (M-04-T08). The marker clears when the reworked actions are verified (engine transition M-06-T06,
  see «Documentation gaps»).
- **Concurrency.** Every engine write locks the order row first inside `App\Support\LockingTransaction` (READ
  COMMITTED, MariaDB 1020 — ADR-0011) and re-reads the task/action/stage, so claims, assignments, completions and
  source signals on one order queue. Claim (M-07-T10) and manual assignment (T01) are therefore atomic: the later
  attempt is refused with `not_from_assigned`. M-22-T01 (disable member) now runs in `LockingTransaction` too because it
  releases tasks (M-07-T09) and locks order rows after the membership row.
- **Tasks (M-07).** Scoped record: branch = the order's (read through the order, so a branch transfer moves its
  tasks), department = its inbox, assignee. Eligibility (`TaskEligibility`): active staff, never support, inside the
  task's department when it has one, and P-24 over the task once assigned. Each task has a follower (the order's
  responsible person, else the owner); disabling a member releases their open work to its inbox/follower and moves
  follow-ups. The executor sees the task, not the order file (WF-08).
- **Q-30 (a).** P-25 is checked on the stage: its follow-up department/person bring it into a supervisor's
  department/assigned scope. `Order::accessDepartmentId()` returns the active stage's follow-up department, so a
  supervisor's department grant covers the order (P-16/P-26) while its current stage is with that department. P-35
  stays with owner/manager (matrix defaults).
- **Q-31 (a).** `work_settings` (single row, `WorkSettings::current()` falls back to defaults): working days (Sun–Thu),
  hours 08:00–17:00, appointment slot 60 min, reminder hours 09:00–20:00, conflicting appointments `allow_with_reason`
  (default) | `block`; P-02 (`UpdateWorkSettings`). Durations elsewhere are calendar days.
- **Appointments (M-16, SHR-03/04).** Resources are actual members (`appointment_members`); a team is checked member
  by member. Proposal (T00) reserves nothing and returns overlaps as warnings. Confirmation (T02) and approving a
  change (T04) first lock the membership rows of the members in id order (first read of a LockingTransaction), then
  check overlaps with confirmed appointments: refused under `block`; under `allow_with_reason` refused without a reason
  and accepted with one (reason kept, all overlapping confirmed appointments marked `conflicted`, members notified).
  Policy changes never cancel existing appointments. «Confirmed with change proposal» is `confirmed` +
  `pending_change`; the original stays until approved. No-show is a marker, not `done`. Q-33 (a): `mapsUrl()` opens the
  maps app; no embedded map.
- **Measurements (COM-07, P-14).** `order_measurements` by line or temporary position, estimated/final; ordinary
  corrections in place with audit. P-14 over the order or through the open measurement task assigned to the actor.
  «Estimated» never satisfies a «final» measurement action.
- **Notifications.** Through `Notifier`/outbox in the transaction: `task.assigned`, `task.unassigned`,
  `appointment.confirmed|changed|cancelled|conflict`. Links re-check P-24 on the live task/appointment and open the
  order page.

## Documentation gaps and contradictions found

1. **Machine numbers in the P5 brief.** The brief maps tasks to M-16 and appointments to M-27; «الحالات والانتقالات»
   v1.1 defines M-07 = task, M-16 = appointment, M-27 = action result. The code follows the analysis documents.
2. **M-06 has no way back from «مكتملة مع تصحيح مطلوب».** Domain 03 says the marker is removed when the correction is
   done; the machine has no transition. The engine adds **M-06-T06** (system) and logs it as such.
3. **M-27 «متاح بسماح» then the real result.** No transition from `allowed` to `verified`; the engine keeps `allowed`
   and stores the arriving source reference (truth recorded, state unchanged).
4. **M-07-T08 (rework) targets «مسندة»** although the previous holder may be disabled; the engine then creates a
   linked correction task (SRS «إعادة العمل تنشئ مهمة تصحيح») instead.
5. **Prototype `FlowStage3`** has the contract approval without a contract-preparation action, which WF-04 forbids;
   the default template adds an optional «إعداد العقد» before it.
6. **P-35 limits («ضمن حد المنحة»)** need financial limits that arrive with P6; P-35 is unlimited for its holders now.
7. **M-16-T02 for P-70 and T07** are customer-portal actions (domain 07); the staff app records confirmations under
   P-54 only.
8. **Branch transfer impact** (P4) still reports appointments as unavailable; tasks move with the order (branch read
   through it), appointments keep their members — to be reviewed with the screens.

## Consequences

- Screens (next wave) call the Actions and read models listed in `docs/p5-engine-api.md`; HTTP controllers, Form
  Requests and routes are added there.
- P6/P7 must report results through `ActionResults::verifyFromSource()` and never write `order_actions` directly.
- Reminders (SHR-06) will use `WorkSettings::canSendReminderAt()` from a scheduled job (not built in this wave).
