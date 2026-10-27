# ADR-0008 — OpsOne support access (M-38, SHR-14)

Status: accepted (Q-25 default (a); M-38 proposed number)

## Decision

- **State machine M-38** on `support_grants`, logged in `state_transitions` and the audit trail:
  `requested → active → expired | revoked`, `requested → rejected | expired`. No automatic extension.
  - T00 request (platform side, P-80): `php artisan support:request-access` until the central app exists. Notifies
    every active member holding P-79 (mandatory notification `support.access_requested`).
  - T01 grant (P-79, password re-entered): areas ⊆ requested, hours ≤ requested and ≤ `OPSONE_SUPPORT_MAX_ACCESS_HOURS`
    (72), request not lapsed, and only areas whose permissions the decider holds **facility-wide** (delegation boundary).
  - T02 reject, T03 revoke (P-79). T04 expire (time service, `support:expire` every minute, and on the support
    identity's own next request).
- **Support identity** = a `users` row with `is_support = true` (`php artisan support:identity`), no membership.
  Any membership row on it is ignored by the Gate.
- **Gate:** a support identity's grants come only from its live grants (`active` and `expires_at` in the future):
  each area maps to view permissions with facility scope — `settings` → P-01/P-02, `organization` → P-03..P-06,
  `audit` → P-63. P-07 and P-79 are never reachable.
- **Read-only:** `EnforceSupportAccess` refuses every non-GET/HEAD request from a support identity (sign-out aside).
- **Sessions:** sign-in (password via `User::canSignIn`, passkey via the middleware) only while a grant is live;
  ending a grant bumps `access_version`, rotates the remember token and deletes stored sessions; the middleware also
  ends the session the moment `expires_at` passes, without waiting for the scheduler.
- **Visibility:** the sign-in and every page opened (route name and path, never the query string) are audited on
  the grant (`support` area), shown on the SupportAccess screen and in the AuditLog for facility-scope P-63 holders.

## Consequences / open

- Support identities are provisioned from the server shell until the central app's SupportInbox/SupportSession exist.
- 2FA for support identities is recommended but not enforced here (ops policy, see P3 security review).
