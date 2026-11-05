# ADR-0036: Installation field app (PWA)

- Status: accepted (2026-11-02). Product owner approved a **minimal** field system: an installable web app (PWA) now,
  wrapped as a store app later.
- Builds on: ADR-0012 (workflow engine), ADR-0007 (outbox), ADR-0006 (private attachments, audit), ADR-0029 (e-signature),
  ADR-0030 (WhatsApp), ADR-0031 (owner dashboard), ADR-0032 / ADR-0035 (delivery remarks, order closure), ADR-0005 (access).
- Code: `App\Installation`, `App\Models\Installation`, `App\Http\Controllers\Field`, `routes/field.php`,
  `resources/js/pages/field`, `public/field-sw.js`, `public/field-icons`. Tests: `tests/Feature/Installation`.

## Decision

### One screen per visit, for the booked technician only
- `GET /field` lists the technician's **own** installation appointments (booked member, P-24 over the appointment;
  awaiting confirmation, confirmed or done) for today and the next 6 days (facility time zone). `GET /field/visits/{id}` is
  the visit screen. `FieldAccess` decides both: a manager's wider P-24 scope does **not** open someone else's visit here
  (403) — the calendar and the order page are the manager's views.
- A booked technician sees the customer's **name, phone (contact's if the order has one) and address**, the items to install
  (names and quantities, never amounts) and the order's need summary. This is need-to-know for their own bookings only.

### Steps (each re-checked on the server, order row locked first, audited)
1. **«وصلت»** (`POST …/arrive`): only on a *confirmed* appointment, once. Stores the arrival time and, if the technician
   ticked «share my location once» and the browser allowed it, **one** reading (lat/long/accuracy). No tracking. The audit
   says only *whether* a location was taken. Starts the installation task (M-07-T02) when it is assigned to this technician.
2. **Photos before/after** (`POST …/photos`, JSON): ≥1 of each is required to finish (max 12 per phase; no «before» after an
   «after»). Captured with `<input capture>`, compressed on the device (longest edge 1600 px, JPEG ~75 %), stored as
   **private attachments owned by the visit** (`InstallationVisit` is an `AttachmentOwner`; view/upload = P-24 over the
   appointment). Each upload carries a device `client_uuid` (unique) so a retry after a lost response returns the stored photo.
3. **Remarks** (`POST …/remarks`): kind `missing_part | damage | extra_work | other` and a description. They follow the
   **multi-remark model of the delivery remarks** (ADR-0032 / ADR-0035 §3) in their own table `installation_remarks`
   (a delivery remark needs a confirmed delivery note; an installation remark hangs on the visit): an order holds any number,
   **every remark is `open` and blocks the order's closure** (`OrderClosure::blockers` → `installation_remark_open` with
   `details.count` and `items[{id, note, description}]`, one line each in the 422) until it is **resolved on its own**
   (`POST /installation-remarks/{id}/resolve`, `resolution` 3..2000, `lock_version?`, P-52 over the order; resolving twice →
   409 `remark_resolved`). The follow-up person is the order's follower, else its responsible person
   (`TaskEligibility::followerOf`), notified in-app (`installation.remark_blocking`, mandatory, target = the order page).
   Read contract like the delivery one: `GET /orders/{order}/installation-remarks` (P-52) → `{remarks: [row]}`, open first
   (oldest first) then resolved (latest first); `InstallationRemarks::row()` = the keys of `DeliveryRemarks::row()` without
   the note, plus `installation_visit_id` and `kind`. The delivery-remark code is untouched.
4. **Customer acceptance signature**: either on the phone (`POST …/signature`: typed name + drawn PNG + consent) or by a link
   (`POST …/signature-link`) to the customer's workspace (`/portal/installations/{visit}`; the customer signs in as usual —
   `PortalAccess::installationVisit` scopes it to their own orders, foreign = 404). Sent by SMS/email through the outbox,
   idempotent per request. The PNG is validated by the e-signature validator (`ESignature::signatureImage`, now public) and
   stored as a generated private attachment with its sha256, the signer's name, method, time, IP and user agent; audited
   (`installation.signed`, source `customer` for the portal). No step-up OTP: this is an acceptance of work done, not a
   contract approval (ADR-0029 keeps OTP for quotes/contracts).
5. **«انتهى»** (`POST …/finish`): refuses without a before photo, an after photo and the signature (409 with the condition).
   Then, in the same transaction and **only through the engine's own Actions** as the technician:
   M-16-T05 (appointment done with the outcome) → M-07-T02/T05 on the installation task (the appointment's `task_id`, else the
   open task of the order's `installation` action) → M-27-T03 via `StageEngine` → M-06-T04 **only if** nothing required is
   open in the stage **and the technician holds P-25** over it (Q-30 a). Otherwise the stage waits for its manager as usual.
   When the stage completion finishes the order (M-04-T03), the existing ADR-0030 event `order_completed` is raised by the
   order observer — no new WhatsApp template. Any refusal by the engine refuses the whole finish (no bypass).
   Status: `finished`, or `finished_with_remarks` when the visit recorded any remark.

### Manager side
- `InstallationStatus`: not started (no visit row) / in progress / finished / finished with remarks — on every calendar row
  (`installation`, null for other kinds) and the appointment page, and in the order page's installation card with the actual
  arrival, duration (arrival → finish), photos and signature (links only for viewers with P-24 over the visit) and remarks
  with «resolve» for P-52 holders.
- Owner dashboard (ADR-0031): `dashboard.installation_first_visit_percent` and `dashboard.installation_avg_minutes`
  (registered in `MetricCatalogue`, defined in `docs/p9-metrics.md`) over visits **finished in range** on orders in the
  viewer's scope, plus the same per team (`installation_teams`: department of the appointment's task, else of the finishing
  member). First-visit = the order's first installation visit **and** finished without a remark. Absent when no visit
  finished in range (never a misleading 0).

### PWA
- Manifest `GET /field/manifest.webmanifest` (Laravel route: the shared nginx serves `.webmanifest` as octet-stream and infra
  files are not edited by features). **Always OpsOne's identity** (name, OpsOne mark icons generated from the design-system
  logo geometry in `public/field-icons`, teal theme) — never the tenant's brand. Linked only on `field.*` pages.
- Service worker `public/field-sw.js`, scope `/field`: built assets and icons cache-first; field pages (full loads and Inertia
  visits, cached separately) network-first with a 6 s timeout and the last copy offline; the list page asks it to warm each
  visit screen. A field page answered by a redirect/401/403/419 wipes the cached pages so customer details do not outlive the
  session. Offline is **read-only** (no offline editing).
- Photo queue: IndexedDB (`opsone-field/uploads`) written by the page, sent oldest-first; retried on `online`, on app open, and by
  Background Sync where supported (Chromium/Android). A refusal that retrying cannot fix (403/404/409/422) is kept as
  «refused» until the technician discards it; 419/5xx stay queued.
- **Persistent login**: `auth.guards.web.remember` = `AUTH_REMEMBER_MINUTES` (default 90 days). The remember cookie is
  HttpOnly and `Secure` with `SESSION_SECURE_COOKIE` / https `APP_URL`. Revocation: «Sign out everywhere» on the member page
  (`POST /org/team/members/{membership}/sessions/revoke`, P-06, never the owner or oneself, audited
  `membership.sessions_revoked`) uses `SessionRevoker` (bumps `access_version`, rotates `remember_token`); any access change or
  disabling already did the same.
- Install instructions: `/field/install` (Android Chrome «Install app»; iPhone Safari Share → «Add to Home Screen»).

### Web push — readiness only, not implemented
- Android Chrome supports Web Push; iOS/iPadOS 16.4+ supports it **only** for a web app added to the home screen. Both work with
  the existing service worker scope.
- It needs VAPID keys (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`, env-only per tenant like ADR-0030 secrets), a
  `push_subscriptions` table per user/device, a subscribe/unsubscribe endpoint, a `push` handler in the worker and a sender
  library (e.g. `minishlink/web-push`) or an outbox channel. That is more than «small», so it is **not** built now: technicians
  are notified in-app and customers by WhatsApp/SMS (ADR-0030). Revisit when the store wrapper is built (a native wrapper would
  use FCM/APNs instead).

## Consequences
- New tables: `installation_visits`, `installation_photos`, `installation_remarks` (additive, `#[Expand]`).
- A technician without P-25 cannot advance the stage; the order page shows the stage awaiting its manager, as for any task.
- A facility whose installation action requires an attachment on the task (`requires_attachment`) must attach it on the task:
  visit photos belong to the visit, and the engine's rule is not bypassed.
- Store wrapper later: the same `/field` URLs in a TWA (Android) / WKWebView shell (iOS); push would move to FCM/APNs.
