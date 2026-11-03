# ADR-0039: Customer appointment requests

- Status: accepted (2026-11-03). Engineering decision on a product-owner requirement: the customer only **requests**
  an appointment, then staff call the customer and fix the appointment themselves. There is no customer approval step.
- Scope: public site section «اطلب موعداً», workspace request page, staff queue, schedule-from-request, owner settings.
- Related: ADR-0012 (workflow engine, M-16 appointments), ADR-0021 (public site, lead form), ADR-0022 (customer
  workspace), ADR-0030 (WhatsApp/SMS customer events), ADR-0037 (No-Code settings pattern, P-104), ADR-0005 (access),
  ADR-0006 (audit), ADR-0007 (notifications/outbox).

## Context

Before this change, staff were the only ones who could create appointments (ApptSchedule, P-54), and customers could
only confirm one or ask to change it from the workspace. Visitors could only send the generic contact form, which
creates an `Interest`. Retailers asked for a direct «request a survey / visit» entry point. The product owner decided
that a request must not book anything: the customer is called, and the agreed time is booked by staff.

## Decision

1. **A separate record, `appointment_requests`.** The record holds the reference `APR-0001`, a unique `submission_key`,
   the source (`site` or `portal`), the status, the kind, the preferred date window and time of day, the name, phone,
   city, address and note, and the branch. It can link to an interest, customer, order, assignee and the booked
   appointment, and it records the close reason. Status moves `new → assigned → scheduled | closed`, and scheduled and
   closed are final. We did not use `appointments`: that table requires an order, members and a time, and the
   request has none of these yet.
2. **Intake.**
   - *Site:* the new section type `appointment` (fixed fields, heading and text only) posts to
     `/{locale}/appointment-request`. It uses the same spam guards as the contact form: honeypot, form-age token and
     its own `site-appointment` limiter with the same limits. A suspected bot gets the same answer, and nothing is
     stored. The request links to the customer with that mobile. If there is none, it links to that mobile's open
     interest. Otherwise it opens a website interest through the same `SubmitLead::open()` (M-03-T00, audited).
   - *Workspace:* `/portal/appointment-requests` takes the customer only from the `customer` guard. The order is
     optional, comes through `PortalAccess` (another customer's id is 404), and must still be open. A customer can hold
     at most 5 open requests, and the route uses the `portal-write` throttle.
   - Both paths are idempotent: the same key replays the first request, including a concurrent insert that loses on
     the unique index.
3. **Access.** The queue reuses **P-54** (the appointment permission) on the request as a `ScopedRecord` (branch,
   assignee). Lists use `ScopedQuery` with the same scope, so a list or count never shows what an open would refuse
   (404). An assignee must cover the request's branch with their own P-54 scope. An `assigned`-only grant is not
   enough. The settings are part of the business rules: P-104 changes them, and P-104 or P-02 can read them. No new
   permission code was needed.
4. **Assignment and response time.** We did not create a `Task` row: tasks belong to an order stage (`order_id`,
   `order_stage_id` NOT NULL), and requests from the site have no order. Instead, the request itself is the
   assignee's work item:
   - `due_at` is set to now plus `response_hours` (settings, 24 by default, null for no SLA) and fixed at assignment.
   - The assignee gets the in-app notice `appointment_request.assigned`.
   - `appointment-requests:remind` runs hourly, inside the reminder hours, and sends `appointment_request.overdue` once
     per request and due time to the assignee and the assigner.
   - A workspace request on an order notifies the order's responsible person (`appointment_request.new`).
   - Notification links re-check P-54 when opened.
5. **Scheduling.** «Book» opens the existing ApptSchedule at `appointments.create?request=ID`.
   - The page is prefilled with the kind, the address (city + address), the preferred date and a time from the time of
     day, and it shows the customer's phone and note.
   - The order list is limited to the request's order, or else to its customer's orders.
   - The confirmation mode defaults to «confirm» by phone, because the customer agreed on the call.
   - `AppointmentController::store` calls `LinkScheduledAppointment` inside the booking transaction. That marks the
     request scheduled and links the appointment, order and customer. A refusal (conflict, order mismatch) leaves the
     request open and no appointment behind.
   - The customer's booked message and later reminder are the existing `CustomerEvents` ones (WhatsApp/SMS through the
     outbox). Nothing new is sent and nothing waits on the customer.
   - A site request with only an interest needs the interest converted to an order first (existing M-03-T02); the
     page says so.
6. **Closing.** The close reason comes from a closed list (`unreachable`, `not_needed`, `duplicate`,
   `booked_elsewhere`, `other`). «Other» requires a note.
7. **Settings (ADR-0037 pattern).** `appointment_request_settings` is one row with `lock_version`; it is audited and
   falls back to the defaults. It holds:
   - the switches for the site and the workspace;
   - the requestable kinds (a subset of `Appointment::KINDS`, measurement and visit by default; none hides the form);
   - each configurable field (`preferred_date`, `time_of_day`, `city`, `address`, `note`) as `off`, `optional` or
     `required`;
   - `response_hours` (1–168, or none).

   Name and phone are always required on the site. Fields set to `off` are dropped even if posted. The preferred
   window runs from today up to 180 days ahead and is at most 30 days long. The page is a new tab in Settings ›
   business rules.
8. **Audit.** The new events are `appointment_request.submitted`, `.assigned`, `.scheduled` and `.closed` (area
   appointments) and `.settings_updated` (area settings).

## Consequences

- Migration `2027_05_01_100000_create_appointment_requests` (`#[Expand]`, two new tables). The schema has no other
  changes. We are pre-launch, so no data migration is needed.
- The `SubmitLead` refactor extracts `open()` and `intakeBranch()`. The contact form's behaviour is unchanged.
- The showcase seeds requests in every state, plus the home-page section. Tests are in
  `tests/Feature/AppointmentRequests`, and screenshots are in `docs/screenshots/appointment-requests/`.
- Deferred: customer self-booking into published slots (still none in v1), and a WhatsApp/SMS acknowledgement on
  submission (the outbox event catalogue is untouched).
