# ADR-0025: P8 internet-facing hardening (proxies, hosts, headers, abuse caps)

- Status: accepted (2026-10-28).
- Scope: the public surfaces P8 opened — site (ADR-0021), customer workspace (ADR-0022), store and Moyasar (ADR-0023),
  tickets (ADR-0024). Result of the P8 merge security review.

## Decision

1. **Trusted proxies.** `config/trustedproxy.php` trusts the private ranges by default (`TRUSTED_PROXIES` narrows it to
   the Traefik network). The app is only reachable through nginx on the Compose network; without this every visitor
   had the proxy's address, so the per-IP caps (OTP, lead form, checkout code) were one global bucket that one client
   could exhaust for everybody, and `isSecure()` was false behind TLS.
2. **Trusted hosts.** `bootstrap/app.php` trusts the `APP_URL` host and its subdomains plus verified custom domains
   (`App\Site\Domain\TrustedHosts`, cached 60 s, cleared after a domain check/removal commits). Outside local/tests any
   other `Host` is refused before it can reach a generated link (password reset, signed URLs, canonical).
3. **Headers in the app** (`SecurityHeaders`, global): `Content-Security-Policy: base-uri 'self'; frame-ancestors 'self';
   object-src 'none'`, `Referrer-Policy: strict-origin-when-cross-origin`, `nosniff`, HSTS (1 year) on HTTPS only.
   Script/form directives are deliberately absent (inline bootstrap scripts; checkout hands off to the provider).
   Private file downloads keep their own `default-src 'none'; sandbox`.
4. **Cookies.** `session.secure` defaults to true when `APP_URL` is `https://` (both the staff and the `_portal` cookie).
5. **Abuse caps.** Customer writes share a `portal-write` limiter per customer account (20/min, 120/h); a customer may
   hold at most `StoreCheckouts::MAX_OPEN_PER_CUSTOMER` (2) checkouts awaiting payment; the lead-form token is bound
   to the form's submission key and expires after `SITE_LEAD_MAX_SECONDS` (2 h); basket-link quantities are capped
   after summing repeated variants.
6. **Correctness fixes found by the review.** A customer may withdraw only a cancellation they requested from the
   account (not one staff recorded); a late `failed` webhook never undoes an invoice a later attempt paid; a Moyasar
   payment counts only on an invoice this app opened (metadata alone is never trusted); portal sign-in codes and
   checkout codes no longer cancel or spend each other; the domain TXT lookup runs before the row lock.

## Consequences / ops

- Server admin: set `APP_URL` to the public `https://` origin; optionally `TRUSTED_PROXIES` to the Traefik network
  CIDR; never publish the nginx port directly in stage/prod. For a custom domain: add a Traefik router rule
  `Host(<domain>)` with a TLS certificate (ACME) for it, then verify the TXT proof in the app (only verified domains are
  trusted hosts). Without the router the domain never reaches the app; without verification the app refuses it.
- A Host that is neither the app URL nor a verified domain gets 400 in stage/prod.
