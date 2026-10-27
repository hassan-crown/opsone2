# ADR-0007 — Notifications, outbound outbox and facility integrations (P3-B)

Status: accepted (Q-22, Q-24, Q-26)

- **Outbox:** every outbound message (mail, SMS) is a row in `outbox_messages`, written inside the caller's
  transaction and delivered by `DeliverOutboxMessage` (Redis queue) **after commit**. The `idempotency_key` is unique,
  delivery claims the row atomically, so a message is never sent twice. A failure is stored (`failed`, scrubbed error),
  retried with backoff (30 s → 2 h, 6 attempts, then `dead`) and never reaches the business transaction.
  `outbox:process` (every minute) re-queues lost jobs; `outbox:prune` drops finished rows after 30 days. The payload is
  encrypted and cleared once sent (it may carry codes and single-use links).
- **In-app notifications:** `user_notifications` (keys + non-sensitive params). Opening goes through
  `notifications.open`, which re-checks `Access::can` on the live target record; the notification never carries access.
  Types live in `NotificationCatalogue`; targets are registered in `NotificationTargets` by domain phases.
  The bell polls the `notifications` shared prop (no Reverb, Q-26).
- **Email:** the facility's own SMTP account (`integration_settings`, encrypted, password write-only, enabling needs a
  successful test, connection changes withdraw it); otherwise the application mailer (`log` in development).
- **SMS:** `SmsChannel` interface with a development log driver; production shows "not configured" and messages are
  `skipped`, never reported as sent (Q-24). WhatsApp is only a `wa.me` link, never a channel.
- **Preferences:** per-user email copy for optional types only; security/account types always email.
