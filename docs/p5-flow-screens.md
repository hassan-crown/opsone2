# P5 flow settings and order progress screens

Screens over the engine in [p5-engine-api.md](p5-engine-api.md): `routes/flow.php`, `App\Http\Controllers\Flow\*`,
`App\Flow\FlowPresenter`, React under `resources/js/components/flow` and `resources/js/pages/flow`. Every write calls the
engine Action named in the engine contract; the controllers only validate the container (`lock_version`, `reason`) and map
exceptions to HTTP (422 with `issues[]` for FlowCheck, 409 with `condition` / `code: stale_version`, 403).

## Flow settings (`/settings/flow`, P-20 to view, P-21 to check/publish/choose a template)

| Route | Action | Notes |
|---|---|---|
| `GET settings/flow`, `GET settings/flow/stages/{stage}?action=` | page `flow/edit` | `FlowEdit`, `FlowStage1-5` (stage deep link), `FlowAction_*` and `FlowAddAction` are dialogs of the same page. Props: `flow` (published definition read-only, `draft`, `lock_version`), `versions` (running orders per version), `options` (catalogue, groups, templates, members, departments), `can`. |
| `PUT settings/flow/draft` | `T02SaveDraft` | Body `{lock_version, stages}`. |
| `DELETE settings/flow/draft` | `T04DiscardDraft` | |
| `POST settings/flow/check` | `FlowCheck::run` | P-20 or P-21; returns `{passes, issues[{code,path,stage,action,message}]}` — the editor links each issue to its stage/action (`FlowCheck`). |
| `POST settings/flow/publish` | `T03PublishFlow` | 422 `issues[]` when the check fails; draft and version stay. |
| `POST settings/flow/template` | `T01ChooseTemplate` | 409 `draft_open` unless `discard_draft`. Used by `WizardFlow` (`org/setup/flow`) too. |

The published version is read-only in the UI; «Edit» works on a local copy that is saved as a draft, checked and published.
Running orders keep their pinned version, shown on the order page and in the versions list.

## Order progress (`/orders/{order}/…`, P-16 to read; writes per the engine's P-xx on the record)

`GET progress` (`WorkflowPresenter::order` + member options), `POST stages/{stage}/complete`, `POST actions/{action}/allow`,
`POST actions/{action}/rework`, `POST pause`, `POST resume`, `POST finish-execution`, `GET upcoming` (not-started stages in the
definition shape + options, P-28), `PUT upcoming` (`EditUpcomingWork`; 422 `issues[]` paths relative to the sent stages).
All responses return the refreshed `progress`. The «Stages and tasks» tab of `orders/show` loads it lazily
(`React.lazy`) so the page's preload `Link` header stays within nginx's default buffer (it exceeded it once).

## Small shared additions

- `orgNav.flow` (P-20) shared prop + navigation item; `SetupController` passes the flow state to the `flow` wizard step.
- `lib/api.ts` `ApiResult.body`: raw JSON of failed responses (`condition`, `issues`).
- Translation keys: `flow.*` and `progress.*` (one contiguous block at the end of `lang/ar.json` and `lang/en.json`).
