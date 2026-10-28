# P5 calendar and appointment screens

Screens over the M-16 Actions of `docs/p5-engine-api.md`. No change to the engine; routes live in `src/routes/calendar.php`.

| Screen | Route | Notes |
|---|---|---|
| `CalWeek` / daily view (Q-32) | `GET /calendar?view=week\|day&date=Y-m-d&branch=&member=` | Sunday-first week in the facility time zone; reads through `AppointmentQueries::between` (P-54 or booked member P-24). A booked member without P-54 sees no customer name. |
| `ApptSchedule` | `GET /appointments/new`, `POST /appointments` | `confirm_mode`: `propose` / `send` / `confirm` (+ `confirmation_method`, `conflict_reason`). Propose + confirm run in one transaction, so a refused confirmation leaves no proposal. Times are facility-local `Y-m-dTH:i`, stored as UTC. |
| Clash preview | `GET /calendar/conflicts` | Returns `{conflicts[], hidden, allows_conflicts}`: the clashing appointments the viewer may see, and a count of the rest (never their details). |
| `ApptChange` and detail | `GET /appointments/{id}`; `POST …/send`, `…/confirm`, `…/change`, `…/change/approve`, `…/change/discard`, `…/cancel`, `…/complete` | Pass `lock_version`; a stale one returns a `lock_version` form error. A refused clash returns `conflict` + `conflict_condition` errors (`conflict_blocked` / `conflict_reason_required`). The original time stays booked until a change is approved. |
| Work settings | `GET/PUT /settings/work` | P-01/P-02 to view, P-02 to save (`UpdateWorkSettings`). Linked from the settings home. |

Map link: `Appointment::mapsUrl()` (Q-33 a), opened in a new tab. Translation keys: `calendar.*`, `appointments.*`.
