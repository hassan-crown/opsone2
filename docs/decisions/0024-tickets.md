# ADR-0024: Customer support tickets (P8 part D)

- Status: accepted (2026-10-28).
- Scope: domain 09 — SRS-SHR-08; permissions P-59 (staff) and P-71 (customer); machine M-21 (T00 create, T01..T06);
  screens `Tickets*` / `TicketPage` (customer, 390px mobile-first, and staff inbox/page), notifications to both sides.
- Builds on: ADR-0005 (staff Gate, scoped grants), ADR-0006 (private files, signed links), ADR-0007 (outbox),
  ADR-0022 (customer guard, `PortalAccess`, allow-list payloads).
- Not in this phase: the «repair task» created from a ticket (prototype `TicketPage`), a «general tickets officer»
  setting in sales settings (the facility owner is the documented default), a ticket tab inside the customer file.

## Decision

### Records

- `tickets`: gapless `TK-YYYY-000123`, one customer, optional order **of that customer**, `branch_id` (the order's
  branch, else the customer's — kept in step by `TransferOrderBranch` / `TransferCustomerBranch`), assignee, type
  (issue/question/request/other), subject, status (M-21), `resolution`, `lock_version`, optional `client_key` (unique per
  customer: a double submit opens one ticket).
- `ticket_messages`: `visibility` = `public` | `internal`, `author_kind` = `customer` | `staff`. The opening text is the
  first public message. **Files hang on the message that carried them** (`TicketMessage` is an `AttachmentOwner`), so an
  internal note's file can never be reached as part of the conversation.

### Machine M-21 (one table, `TicketStatus::transitions()`, applied only by `TicketTransitions`)

| Code | From → To | Who |
|---|---|---|
| T00 | → new | customer (creation) |
| T01 take | new → in_progress | P-59; the actor becomes the assignee |
| T02 request info | in_progress → awaiting_customer | P-59; a public question |
| T03 customer reply | awaiting_customer → in_progress | the customer |
| T04 resolve | in_progress → resolved | P-59; the description is the last public message |
| T05 close | resolved → closed | P-59 or the customer |
| T06 reopen | resolved/closed → in_progress | P-59 with a reason, or the customer's reply |

A transition is validated against the table **before** any write and applied with the row lock held; the row, the
`state_transitions` log and the audit row change together. A customer reply on a new/in-progress ticket adds a message
only. A public staff reply is allowed while in progress or awaiting the customer; a new ticket is taken first and a
resolved/closed one reopened first; an internal note is allowed in every state. Assigning (no status change) needs P-59,
an active member holding P-59 over the ticket, and is refused on a closed ticket. A reply after resolution reopens the
**same** ticket (never a new one, no money or delivery effect).

### Access

- Staff: P-59 only, through the central Gate on the ticket (`ScopedRecord`: branch + assignee; no `own`/department data
  on tickets). The inbox uses `ScopedQuery` with the same grants, so a list never shows what a direct open refuses. The
  responsible person at creation/reopening is the order's owner when eligible, else the facility owner.
- Customer: `PortalAccess::tickets()` by `customer_id` only; a foreign id is 404. The order link is validated against
  `PortalAccess::orders()` (a foreign or unknown order id is a validation error and writes nothing). `canDownload`
  admits only the files of **public** messages of the customer's own tickets.
- The customer payload (`TicketPresenter::customerDetail`) is an allow-list: public messages, no staff names, no
  assignee, no internal data.

### Notifications (outbox, in the business transaction)

- To the customer (SMS + the account's email): a public reply, taking, a question, resolution, closing or reopening by
  staff — **text only says there is an update for ticket TK-…; no message body, no note, no file**. The content stays in
  the account, so a failing channel never loses it and a retry never repeats it (idempotency key per message/transition).
- To staff (in-app + optional mail, link re-checks P-59): new ticket, customer reply, assignment.

## Consequences

- Staff nav item «Tickets» (flag `orgNav.tickets`) instead of a tab inside Customers; the customer workspace gains a
  «Support» tab and a «Report a problem» entry on the order page (`/portal/tickets/new?order=ID`).
- `routes/customer-tickets.php` is loaded next to `routes/customer.php` by `PortalServiceProvider`.
