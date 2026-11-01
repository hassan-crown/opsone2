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
