---
name: code-review
description: Review an existing Laravel/React project change or pull request for correctness, security, regressions, test coverage, and infrastructure risks. Use before merging a PR.
---

# Code Review workflow

1. Read project standards and the exact diff; review only the scoped changes plus necessary context.
2. Prioritize security, data integrity, permissions, auth, queue idempotency, schema changes and regressions.
3. Confirm changes obey `src/` vs root infrastructure boundary, UID/GID and secrets policy.
4. Check tested behavior, migrations and compatibility with installed versions.
5. Output Arabic findings with severity, path/line, reproduction/evidence, mitigation and a concise conclusion.
6. Separate proven defects from risks/hypotheses. Never claim tests passed without executing them.
