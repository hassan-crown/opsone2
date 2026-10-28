# P5 task screens — routes and read models

Screens of M-07 on top of the engine ([p5-engine-api.md](p5-engine-api.md)). Routes live in `src/routes/tasks.php`; controllers in
`App\Http\Controllers\Tasks`; read models in `App\Tasks` (`TaskPresenter`, `TaskViews`). Every write is a JSON call that ends in an engine
Action, which authorizes on the task (central Gate); refusals follow the engine's HTTP mapping (403 / 409 / 422).

| Route | Screen | Notes |
|---|---|---|
| `GET /tasks?view=all\|mine\|inbox\|waiting\|followed\|completed&q=&status=&overdue=1` | `TaskList`, `TaskEmpty` | Inertia, or JSON `{tasks, counts, limit}`. P-24 per row via `TaskQueries` (≤ 200). |
| `GET /tasks/today` | `ExecHomeMobile` | Also rendered by `/dashboard` for a pure executor (P-24 without P-16/P-22) as `exec`. |
| `GET /tasks/{task}` | `TaskPage` / `TaskMobile` (+ `TaskWaiting` dialog) | P-24 on the task. No money, no customer file (WF-08); the customer name appears only in rows and the visit block. |
| `GET /tasks/{task}/assign` | `TaskAssign` | P-22 (assign / route to a department) or P-23 (reassign). `TaskQueries::eligibleFor` gives the members with their open work. |
| `POST /tasks/{task}/claim \| assign \| reassign \| start \| wait \| resolve \| complete \| cancel` | — | Bodies: `lock_version?`; assign `assignee_id` or `department_id`; reassign `assignee_id, reason`; wait `need, follow_up_on`; complete `result_note?`; cancel `reason`. Response `{task}`. |
| `POST /tasks/{task}/attachments` | `TaskPage` | Multipart `file`; `AttachmentService` with the task as owner (view/upload P-24). |
| `GET /tasks/{task}/measure`, `POST .../measurements`, `PUT .../measurements/{id}` | measurement entry (Q-32) | Mobile-first form; `RecordMeasurement` authorizes through the assigned open measurement task (P-14 path) and validates. A measurement of another order is a 404. |

## Small additions to the engine

- `Task` implements `AttachmentOwner` (P-24 view/upload) so required attachments can actually be uploaded.
- `TaskQueries::completed()` — my work finished in the last 30 days (the «المكتملة» tab).
- `orgNav.tasks` (P-24 or P-22) for the sidebar entry; dashboard props `taskCards`, `isExecutor`, `exec`.

## Behaviour worth knowing

- The inbox is visible to members whose P-24 reaches unassigned work (department / branch scope) — an executor holds only what is assigned to
  them, so the claim button appears for sales/supervisor-type members of the department. The department_inbox home widget stays P-22.
- A supervisor's scope follows the task's department, so department work for orders of another branch stays visible to them (matrix P-22).
- Home cards: `my_tasks {count, overdue, waiting, inbox, items[3]}`, `department_inbox {count, items[3]}` (P-22 only), built from the same
  Gate-filtered queries as the lists, so counts never include out-of-scope work. Counts are capped by `TaskQueries::LIMIT` (200).
- Translation keys: contiguous `tasks.*` / `exec.*` block after `home.widget.*`.
