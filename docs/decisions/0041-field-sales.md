# ADR-0041: Field sales

- Status: accepted (2026-11-04). Engineering decision on a product-owner requirement: field sales («البيع الميداني») is a
  third revenue channel beside the showroom and the online store. A sales rep visits the customer at home or on site
  and closes the sale there, and every sale is attributed to its channel.
- Scope: rep role template, supervisor dispatch, the rep's part of the field app, the order's sales channel and
  seller, channel and rep reports, the settings as a configuration-release section, and the showcase.
- Related: ADR-0005 (access), ADR-0009 (customers and interests, M-03), ADR-0012 (workflow, M-16 appointments),
  ADR-0016 (commissions), ADR-0026 (reports), ADR-0029 (signing links), ADR-0036 (installation field app), ADR-0037 /
  ADR-0040 (No-Code settings and releases), ADR-0039 (customer appointment requests).

## Context

The product already had most of the remote sales path: leads (`interests`) with an assignee, appointments of the
kinds `visit` and `measurement`, the customer's appointment requests (ADR-0039), the quote → contract → e-signature →
workspace payment flow, and commissions pinned per order (`order_sales`). The field app (ADR-0036) was limited to
installations through `FieldAccess`. Three things were missing. No role fitted a rep who should see only their own
customers. Nobody could hand a lead to a rep with a response time. Orders did not record where they were sold.

## Decision

1. **Role template `field_sales` («بائع ميداني»).** It is a new default template with least-privilege grants: P-09,
   P-10, P-14, P-15, P-16, P-17, P-24, P-29, P-30, P-32, P-37 and P-54, all at `assigned` scope, plus P-94 at `own`
   scope. It holds no finance administration (P-38–P-41, P-43, P-95–P-98), no reports (P-60/P-62) and no dispatch or
   channel change (P-105/P-106). Like any template, it can be edited on the roles screens. A **rep** is an active
   member who holds this template (`FieldReps`), and a rep covers the branches of their own membership.
   - To make «own records only» possible, the matrix scopes change in two places. **P-09** gains `assigned`, so the
     rep can follow up, close and convert the leads assigned to them. **P-15** gains `assigned`, so the rep can convert
     an assigned lead and open an order assigned to themself.
   - `CustomerAccess::authorizeCreate` adds a guard. When a member's P-09 covers a new customer or lead only as its
     assignee, the branch must be one of that member's branches. An `assigned` grant therefore never lets a rep open
     records in another branch.
   - Visibility follows from these grants. The rep's lead list and screen show only the leads assigned to them. Orders
     follow `assignee_id`, appointments follow their members, and customers follow `assignee_id`.
2. **Dispatch (P-105, new).** P-105 is «توزيع الاهتمامات وطلبات المواعيد على البائعين الميدانيين». Its scopes are
   branches, region and tenant. It goes to owner, manager and supervisor by default; for the supervisor, the narrowest
   allowed scope (`branches`) applies.
   - `FieldDispatch` assigns an open lead, or an open appointment request of a kind reps handle, to a rep who covers
     the record's branch. The record's assignee becomes the rep, because the rep's `assigned` grants follow it.
   - An open `field_assignments` row is the rep's task. Its response time is `response_hours` from the settings in
     force. The rep gets the `field_sales.lead_assigned` or `field_sales.request_assigned` notification, and the
     dispatch is audited.
   - Re-dispatching ends the open row as `reassigned`. The subject's own end closes it as `done`, with the outcome
     converted, closed or scheduled, through the model events. If another path changes the subject's assignee, the
     row also ends.
   - Every hour, `field-sales:remind` sends `field_sales.lead_overdue` to the rep and the dispatcher, once per
     assignment and inside the reminder hours. A dispatched request carries the same due time on the request, so the
     ADR-0039 reminder covers it.
   - The board is at `/field-sales`. It lists the not-dispatched, dispatched and all open records, each rep with their
     open work, and offers only the reps who cover each record's branch.
3. **Field app for reps.** `FieldAccess` now works by kind.
   - An `installation` still needs P-24, and its booked members are its workers (ADR-0036). `FieldVisits` and
     `InstallationRemarks` now refuse any other kind.
   - The sales kinds are those the settings hand to reps (`visit`, `measurement`). They need field sales on, booking
     on the appointment and P-54.
   - `/field` adds the rep's open leads and requests, sorted by response time with late ones marked. A lead screen
     offers call and WhatsApp, take (M-03-T01), close with a reason (T03) and convert (T02).
   - A sales visit screen runs: «وصلت», then notes, site photos (the ADR-0036 device queue, idempotent `client_uuid`,
     private attachments on `sales_visits`), measurements (RecordMeasurement: P-14 over the order, or through the
     appointment's task), quote and customer links, and finally «انتهت الزيارة» (M-16-T05 with the outcome).
   - For the quote and links, the rep can create a quote draft (M-08-T00, priced on the agreement page), send it
     (T01), send the signing link (ADR-0029) and send the workspace invitation. All of these go through the existing
     actions with the rep's own grants. The field app adds no access of its own, and a document of another order is
     404.
4. **Sales channel and seller.** `orders.sales_channel` takes the values `showroom | online_store | field_sales`.
   `orders.seller_id` stores the rep who sold the order. Both are set in `OrderOpener`:
   - store orders (`openFromStore`, including basket links) → `online_store`;
   - while field sales is on, an order converted from a lead with an open dispatch → `field_sales`, sold by that rep;
   - while field sales is on, an order a rep opens themself → `field_sales`, sold by them;
   - anything else → `showroom`.
   P-106 (new; owner and manager; branches, region or tenant scope) corrects the channel and seller from the order
   page. The change is audited (`order.sales_channel_changed`, before and after), and a field seller must cover the
   order's branch. **Commissions:** `SaleOwnership` pins the seller first, so the existing `order_sales` /
   `CommissionRate` rules apply to the rep (`owner_source = seller`). A sale already pinned keeps its owner; moving it
   stays a sale correction (P-98, ADR-0016).
5. **Reports.**
   - `channels` shows, per channel, orders created, booked sales (the active agreement of custom orders first approved
     in range plus store checkouts paid in range) and net collected, with the field share. Amounts need P-37.
   - `field_sales` shows, per rep, leads and requests dispatched, visits finished, dispatched leads converted,
     conversion %, field orders, booked sales (P-37) and commission accrued on their field orders (P-94). It follows
     the viewer's P-60 branches.
   - Every number is registered in `MetricCatalogue`, and CSV exports reuse the same definitions.
6. **Settings as a release section.** `field_sales_settings` is the draft: on/off, the kinds reps handle and the
   response time. `ConfigSnapshot` gains the `field_sales` section, so the settings follow ADR-0040: draft, publish,
   change log, rollback through `ConfigRestorer`, and the draft banner. The application reads only the release
   (`FieldSalesConfig::current()`). Before a release carries the section, the built-in defaults apply: field sales
   off. Editing needs P-104; reading needs P-104 or P-02. With field sales off, no dispatch happens, reps see no sales
   visits and no new order is marked `field_sales`. Existing orders keep their channel.

7. **The supervisor controls the rep, inside two ceilings (`FieldRepAccess`).** This was added at the product owner's
   request.
   - *Who:* a member holding P-105 over **every** branch of the rep's membership, at branch, region or facility scope.
     A supervisor outside that branch or region gets 404 on the screens and an authorization refusal in the service.
   - *What:* six rep permissions can be switched: P-29 (prepare and price quotes, incl. ready-goods staff discount),
     P-30 (send documents and signing links), P-15 (convert leads, open orders), P-37 (see the agreement value,
     collections and dues), P-14 (measurements) and P-32 (contracts). The discount is the rep's own `discount_limits`
     row (custom and ready).
   - *Owner's ceiling:* the rep's role templates (edited on the roles screens) decide which permissions a rep may hold
     at all; a permission outside them can be neither turned on nor off here. `max_discount_bp` in the settings
     release section caps the discount. It defaults to 0, so reps get no discount until the owner sets one.
   - *Supervisor's ceiling:* «off» writes a deny `member_grants` row with `granted_by` set to the supervisor. «On»
     deletes that row, and it counts as a delegation: `DelegationGuard` requires the supervisor to hold the permission
     themself. The discount also may not exceed the supervisor's own limit (`DiscountLimits::of`).
   - *Effect:* immediate. The Gate rebuilds the rep's grants on their next request, and the rep stays signed in.
     Unlike role changes (M-22-T03), no session is revoked.
   - *Audit:* `field_sales.rep_permission_changed` and `field_sales.rep_discount_changed` (before/after) are recorded
     on the rep's membership.
   «Reassign their own leads» is not a switch: re-dispatch stays with P-105 holders (see Deferred).
8. **Supervisor follow-up (`FieldTeam`).**
   - `/field-sales/team` lists, per rep the supervisor manages: open leads (new / following), visits today and in the
     next 7 days, late items (dispatched leads past their response time and the rep's workflow tasks past their due
     date), conversions in 30 days and open orders.
   - `/field-sales/team/{rep}` adds the switches and discount, the lead, visit and late-task lists, and the rep's last
     50 orders as `seller_id`. Each order shows the active stage and stages done out of all stages (workshop and
     factory stages are flow stages), dues still owed (only where the supervisor holds P-37 over the order), delivery
     (none, partial or complete) and installation status with open remarks.
   - Every row links to the existing lead, appointment, task or order screen; nothing is duplicated. The order
     summaries are visible under P-105, so a supervisor sees the follow-up even without P-16. Opening an order still
     needs P-16.
   - `field-sales:remind` sends `field_sales.team_overdue` (P-105, opening the team view) to every supervisor who
     manages the rep and to the dispatcher. It fires once per late dispatched lead or request and once per late task
     and due time. The rep still gets `field_sales.lead_overdue`.

## Consequences

- One migration (`#[Expand]`) adds two columns to `orders` and the tables `field_sales_settings`, `field_assignments`,
  `sales_visits` and `sales_visit_photos`. There is no backfill: pre-launch, existing orders default to `showroom`.
- Existing installations get the new template, P-105 and P-106 only from `RoleTemplateSeeder`, which adds missing
  templates and leaves edited ones alone. The catalogue now has 106 codes.
- A rep cannot book an appointment themselves. The visit is booked by someone holding P-54 over the branch (supervisor,
  manager) with the rep as a member, or from an appointment request through ADR-0039's booking. A rep whose lead's
  mobile matches a customer they cannot see gets the existing «customer hidden» refusal on conversion. The supervisor
  then converts the lead or assigns the customer.
- The supervisor template does not hold P-29/P-30/P-15/P-37/P-32 by default. A supervisor can therefore restrict a rep
  but not re-enable those permissions; a manager can, as can a supervisor the owner granted them to.

## Deferred

- Rep self-booking from the field app, with a calendar of free slots.
- A switch letting a rep hand their own leads to another rep. Today the dispatcher (P-105) re-dispatches.
- The showcase's field orders stay in the flow's first stage: the story does not run their workflow tasks. Their
  tasks therefore appear late in the team view, as a stalled pipeline would.
- Live location or route planning. The visit records no location.
- Region-level dispatch for supervisors without branches, and rep-to-rep handover inside the field app.
- Offline queueing of notes and measurements. Only photos are queued.
