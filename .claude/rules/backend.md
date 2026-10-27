# Laravel backend rules

- Prefer framework-native Laravel conventions: controllers, requests, policies, resources, events, jobs, migrations.
- Validate untrusted input using Form Requests; authorize every sensitive action via policies/gates/middleware.
- Keep controllers thin; place complex workflows in explicit action/service classes when justified.
- Use transactions for atomic state changes; preserve idempotency for payment/order/webhook operations.
- Never log passwords, API tokens, PII, payment credentials or full sensitive request bodies.
- Do not add libraries when built-in Laravel features suffice; verify versions and compatibility first.
- Use database migrations, never edit deployed schema manually. No destructive migration without approval.
- Use Redis for cache/queue/session only if configured; document queue/job retry/timeout behavior.
- Keep external API calls bounded (timeouts, retries, error handling), make side effects testable.
- For multi-tenancy, establish explicit tenant isolation before adding queries or permission checks.
- For API-only projects use Resources, versioned routes where required, and consistent error contracts.
