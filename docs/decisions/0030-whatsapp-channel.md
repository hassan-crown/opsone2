# ADR-0030 — WhatsApp channel for customer notifications

Status: accepted (product owner request; amends ADR-0007 «WhatsApp is only a `wa.me` link»)

## Decision
- **One door:** `App\Whatsapp\CustomerMessenger` decides the channel for every customer event, writes a row in
  `customer_messages` (the staff-visible log) and queues the message through the existing transactional outbox
  (`OutboxChannel::Whatsapp`, ADR-0007). The outbox keeps its guarantees: written in the business transaction, delivered
  after commit, atomic claim, backoff 30 s → 2 h, 6 attempts. The idempotency key (`{key}:wa`, SMS `{key}:sms`) makes a repeat a no-op.
- **Events** (`WhatsAppEvent`): measurement booked / reminder 24 h, design ready, quote ready, payment due, receipt approved,
  installation scheduled / reminder 24 h, order completed (+ link to the customer's workspace for feedback). They are
  raised by model observers (appointment, agreement document, order — the actions are untouched), the finance notices
  (`CollectionNotices`) and the `whatsapp:remind` schedule (hourly, inside the facility's reminder hours).
- **Templates only.** Each event maps to one approved template name (`opsone_<event>`, override per environment) and
  an ordered parameter list; the language code (`ar` / `en`) is the customer's locale. The same text (`lang/*.json`,
  `whatsapp.event.*`) is the SMS fallback and the log text.
- **Drivers** behind `WhatsAppChannel`, chosen by `WHATSAPP_DRIVER`: `log` (local default; records template name and
  parameter *count* only — never parameters, text or the full number), `fake` (tests), `cloud` (WhatsApp Cloud API, Meta).
- **Consent / channel choice.** WhatsApp is used only when the facility enabled the channel **and** the event, the driver is
  configured, the customer has not opted out (`customers.whatsapp_opt_out_at`; set by staff with P-10 or by the customer
  replying STOP / إيقاف on WhatsApp) and their preferred channel is WhatsApp or unset. Otherwise SMS. Consent and switches
  are re-checked again at delivery time.
- **Fallback to SMS** (same text, once): channel off, driver unconfigured, customer opted out meanwhile, provider refusal
  that retrying cannot fix (recipient not on WhatsApp, template missing, auth/4xx), retries exhausted, or the provider
  reporting the send failed later (webhook). SMS then follows the existing SMS rules.
- **Owner settings:** Settings → Integrations (P-02): on/off and which events send. They live in `integration_settings`
  (key `whatsapp`) and hold **nothing secret**; saving is audited.
- **Message log:** on the customer page, status queued → sent → delivered → read / failed, channel, fallback. The text is
  encrypted at rest and returned only to staff who may see the customer's sensitive data (P-10); other staff who can open the
  customer see event, channel and status only.

## Credentials — sourcing (no secret in the repo, DB or UI)
All come from the server environment (`src/.env`, never committed), read in `config/whatsapp.php`:

| Variable | Purpose |
|---|---|
| `WHATSAPP_DRIVER` | `log` (default) / `fake` / `cloud` |
| `WHATSAPP_PHONE_NUMBER_ID` | sender phone number id (Meta Business) |
| `WHATSAPP_ACCESS_TOKEN` | permanent system-user token (WhatsApp Business Management) |
| `WHATSAPP_APP_SECRET` | signs webhook bodies; **without it every webhook is refused** |
| `WHATSAPP_VERIFY_TOKEN` | one-time webhook URL verification (`hub.verify_token`) |
| `WHATSAPP_API_VERSION`, `WHATSAPP_BASE_URL`, `WHATSAPP_TIMEOUT`, `WHATSAPP_CONNECT_TIMEOUT`, `WHATSAPP_RETRIES` | tuning (defaults: v21.0, 10 s, 5 s, 2) |
| `WHATSAPP_TEMPLATE_PREFIX` | template name prefix (default `opsone_`) |

Each tenant deployment holds its own values (ADR-0002); rotate by editing the environment and restarting workers. Until
a real account exists the driver stays `log` and nothing leaves the server. Templates must be created and approved in
Meta Business Manager with the parameter order in `WhatsAppEvent::params()` (body `{{1}}…`).

## Webhook
`GET/POST /webhooks/whatsapp` — no session, no CSRF. POST requires `X-Hub-Signature-256` = `sha256=` + HMAC-SHA256 of the
raw body with the app secret (constant-time compare); wrong/missing signature or missing secret → 403 before parsing.
Statuses (`sent`, `delivered`, `read`, `failed`) only move forward, so replays and out-of-order delivery are harmless.
GET answers Meta's URL verification only with the verify token. Throttled 120/min.

## Limits and consequences
- **Delivery guarantee:** at-most-once per outbox row. The provider id is stored as soon as it is returned and an attempt that
  finds one never re-sends; the Cloud API has no idempotency header, so a crash in the instant between the provider
  accepting and the id being saved could still repeat one message.
- Provider error text is never stored or logged (it can echo number or token): only HTTP status / Meta error code.
- Marketing messages are out of scope; only transactional events above.
- A tenant whose customers never consented outside the app should keep their preferred channel / opt-out data accurate;
  the app does not collect opt-in on the customer's behalf.

## Amendment (2026-11-09) — WhatsApp inbox (AI-3, ADR-0050, Q-72)

Status: accepted (product owner decisions Q-72 and Q-89..Q-96 on 2026-11-09; plan `docs/analysis/ai-v1-plan.md`, Axis 2).
No model or AI is involved: this is a standalone inbox that later batches (AI-4 onward) build on.

**Account (Q-72).** Each facility uses its **own** Meta WhatsApp Cloud API account and number; messaging cost and
responsibility stay with the facility. The same environment variables as above serve both notifications and the inbox
(one deployment per tenant, ADR-0002). The number must be moved from the WhatsApp Business phone app to the Cloud API
(or a new number used) — the main operational hurdle, handled at onboarding.

**Inbound.** The same signed webhook (`POST /webhooks/whatsapp`, signature checked before parsing, constant-time,
unsigned or wrong → 403) now also writes every inbound message into `conversations` / `conversation_messages`
(`App\Inbox\InboundRecorder`), only while the inbox is switched on. Text is encrypted at rest like `customer_messages.body`.
A change whose `metadata.phone_number_id` is not the configured `WHATSAPP_PHONE_NUMBER_ID` is ignored.
**Idempotent:** the Meta message id is unique, so a replay or parallel re-delivery stores nothing twice (no second unread
count, no second download). Reactions and system events are not stored; locations, contacts and other types are stored as
«unsupported» without content. STOP / إيقاف keeps working as above, inbox on or off.

**Conversations and scope.** One conversation per WhatsApp number (E.164). It is linked to a customer by `customers.mobile`
on its first message, else it stays an «unknown contact» until staff link it (`ConversationCustomers::link`, needs P-108 on
the conversation and the right to open the customer, audited). Its branch is the customer's branch — kept in step when the
customer is transferred — and, for an unknown contact, the owner's «default inbox branch» (or none: then only
facility-wide grants and the assignee see it). New permission **P-108** «WhatsApp inbox: read, reply and assign»
(scopes assigned / branches / region / tenant; default roles owner, manager, sales). It is checked server-side against the
conversation's branch and assignee for the list (`ScopedQuery`), the page, every write and every file download. Reading
the text needs P-108 rather than P-10 (deviation from the plan's wording): the inbox is the explicit grant for working
customers' messages, and replying requires reading them.

**Assignment.** P-108 on the conversation; the new assignee must be an active, non-support member who would hold P-108
over it once assigned. A new conversation takes the linked customer's responsible person. Audited
(`conversation.assigned`).

**24-hour window.** Counted from the contact's last inbound message (Meta's timestamp, never in the future). Inside it
staff may send free text; outside it only a template from the owner's list of Meta-approved templates (name, language,
exact number of body parameters). Enforced when the reply is queued **and again at delivery** (`window_closed`).
Replies to a customer who opted out are refused. Utility messages inside the window are paid since 2026-10-01 — the
facility's cost (Q-72).

**Outbound.** `App\Inbox\ReplySender` writes the message (with the composer's one-time UUID key: a double submit returns
the first message) and queues `SendConversationMessage`: atomic claim queued → sending, provider id saved as soon as
returned, retryable refusals back to queued (30 s / 2 min / 5 min, 4 attempts), permanent refusals `failed` with Meta's
code only. Delivery statuses from the webhook move replies forward only. No SMS fallback for inbox replies (staff see the
failure in the thread). Same at-most-once caveat as notifications.

**Media.** Images (jpeg/png/webp), PDF, audio (ogg/mpeg/mp4/aac/amr) and mp4 video, up to `WHATSAPP_INBOX_MAX_MEDIA_KB`
(default 16 MB). The declared type is checked on arrival (anything else is `rejected` and never downloaded); the job
`FetchInboundMedia` re-checks the declared size, downloads only from Meta hosts with the bearer token, and checks the
actual size and the detected type of the bytes before storing them as a **private attachment of the conversation**
(ADR-0006: random name, storage quota, download link re-checks P-108). Media URLs expire, so retries are short; then
`failed`.

**Switch.** Settings → Integrations (P-02, audited `integrations.whatsapp_inbox_saved`, nothing secret): inbox on/off,
default branch for unknown numbers, approved reply templates. Off = inbound messages are not stored, the inbox pages
answer 404, nothing can be sent from them; notifications, statuses and STOP are unaffected.

| Variable | Purpose |
|---|---|
| `WHATSAPP_INBOX_MAX_MEDIA_KB` | largest received file kept (default 16384) |

Code: `app/Inbox/*`, `app/Whatsapp/Inbox/*` (transport drivers log / fake / cloud, chosen by `WHATSAPP_DRIVER`),
`app/Models/Inbox/*`, `InboxController`, `routes/inbox.php`, `resources/js/pages/inbox/*`. Tests:
`tests/Feature/Inbox/WhatsappInboxTest.php`.

**Not in AI-3 (follow-ups):** staff sending files, closing/archiving conversations, notifying the assignee of a new
message, a side panel with the customer's orders, and retention/deletion of conversation data (PDPL) beyond the facility
closure export.
