# ADR-0009 — Customers, interests and bilingual content (COM-01..03, M-03, Q-27, Q-28)

Status: accepted as decided-default (Q-27 option (a), Q-28 option (a)); reversible

## Decisions

- **Q-27 — deviation from COM-01's text.** COM-01 asks for a mobile **and** an email, both unique. Implemented:
  the **mobile is required**, and the **email is required only when customer-area login is enabled** for that customer
  (`customers.portal_login_enabled`, rule `required` on that flag). An email that is filled is still unique
  (lowercased). To revert to the text of COM-01: make `email` always required in `CustomerRequest` and drop the flag
  condition; no schema change.
- **Uniqueness inside the facility** is decided by the database: unique keys on the normalized mobile (E.164,
  `App\Customers\Phone`: `05…`, `5…`, `+966…`, `00966…`, Arabic-Indic digits all map to `+9665XXXXXXXX`) and the
  lowercased email. Form Requests normalize before validating so users get field errors; a lost race surfaces as the
  same error (`UniqueConstraintViolationException` → `ValidationException`). Contact persons are not accounts and are
  not subject to it (COM-02).
- **Business fields** (organizations only, validated only when filled): commercial registration = 10 digits; VAT number
  = 15 digits, first and last `3` (Saudi formats, not generalized to other countries). Individuals' business fields are dropped.
- **Contacts:** exactly one active primary whenever any active contact exists. Enforced in `ManageContacts` under a
  customer row lock and by the database (`primary_of` unique: holds the customer id while primary). Removal sets
  `removed_at` (history kept); removing the primary promotes the oldest active contact.
- **Access:** `Customer` and `Interest` implement `ScopedRecord` (branch = `branch_id`, assignee, owner = creator;
  no department). Viewing a customer = P-09 or P-10 over the record; creating = P-09 in the target branch; editing and
  contacts = P-10; viewing an interest = P-09 or P-15; follow/close/reopen = P-09; **convert = P-15** (plus P-09
  when a new customer is created, via `CreateCustomer`). Lists and search narrow the query with
  `Access\ScopedQuery` (mirrors `ScopeResolver` for single-branch records) and search re-checks each row with the Gate.
- **Hidden fields:** `internal_notes`, `commercial_registration`, `vat_number`, `office_phone` are in the Inertia
  payload only for holders of P-10 over the record (`Customer::SENSITIVE`, `CustomerPresenter::detail`); a P-09-only
  viewer gets a payload without those keys. They are redacted in audit before/after values.
  Customer financial fields (balance, credit) do not exist yet; they will use P-37 when orders/finance arrive.
- **M-03** (`app/Actions/StateMachines/M03Interest`): T00 create (`new`), T01 take follow-up (`new→following`),
  T02 convert (`new|following→converted`, once), T03 close (reason required; note required for «other»),
  T04 reopen (`closed→following`, reason + owner). Every step writes `state_transitions` and an audit event inside
  the transaction. **Conversion is one-time:** the interest row is locked; a second call throws
  `InvalidTransition(already_converted)` before any write (no second customer, transition or audit row); over HTTP the
  controller turns it into a redirect to the interest (idempotent). The customer is the linked one, else matched by
  mobile/email (never one the actor cannot see — a validation error instead of revealing it), else created from the
  interest (mobile required).
- **Q-28 — bilingual content:** JSON `{ar, en}` column with the cast `App\Support\Translatable\TranslatableString`
  (value object `Translated`, `->get(?string $locale)` falls back to the other locale). Shared with the catalog;
  customers do not use it (a person has one name).

## Not in this part

Customer addresses, the minimal order created on conversion (the interest keeps the customer link; an order reference
is added with orders), branch transfer, and customer-area login itself.
