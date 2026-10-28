# ADR-0028: Operations for a single-tenant deployment (backup, restore, upgrade, health, license states, closure)

- Status: accepted (2026-10-28). Q-54 values are the documented default until the server admin approves them.
- Scope: P9 part C — NFR-11, NFR-12, NFR-15, NFR-16, SUB-12/SUB-13 (facility side), FIN-12, Q-53 (a), Q-54.
- Related: ADR-0002 (one deployment per tenant), ADR-0004/0005 (license and Gate), ADR-0006 (private files),
  `docs/runbook.md` (procedures), `docs/proposals/manage-sh-operations.md` (proposed root-script changes).

## Decision

### 1. Backup and restore are app-level artisan commands

- `ops:backup` writes ONE archive per run: a logical snapshot of this deployment's database (per table its DDL as the
  engine reports it — `SHOW CREATE TABLE` / `sqlite_master` — and its rows as NDJSON ordered by primary key), the
  private file disks (`ops.backup.file_disks`, default `attachments`) and `manifest.json` (format, `APP_VERSION`,
  engine, deployment id, migration names and batch, per-table rows and sha256, per-entry sha256).
- **Consistency:** all tables are read inside one `REPEATABLE READ` transaction, i.e. one InnoDB snapshot.
- **Why not `mariadb-dump`:** the app image has no MariaDB client and infra files are out of scope here; a PHP dump
  works through the deployment's own connection config (so it can only ever see this tenant's database), runs the same
  on SQLite in tests, and lets the restore prove equality byte-for-byte (the same encoder hashes source and result).
  Trade-off: no triggers/routines/views are captured (the app has none); very large tables are streamed in 1000-row
  chunks. A future switch to `mariadb-dump` stays possible behind the same manifest.
- **Encryption:** libsodium secretstream (XChaCha20-Poly1305, 1 MiB chunks, FINAL tag → truncation is detected) with
  `OPS_BACKUP_KEY` — 32 random bytes, never the `APP_KEY` (refused if equal). The file starts with a key fingerprint so
  a wrong key is reported as such. A plaintext sidecar `.json` holds only size, sha256, version and date.
- **Storage:** disk `OPS_BACKUP_DISK` (`backups` = `storage/app/backups`, private; `backups_s3` = S3-compatible). A disk
  that is public, web-served or under `public/` is refused. Plaintext exists only in a 0700 work directory under
  `storage/app/ops-tmp` that is deleted in `finally`.
- **Schedule:** `ops:backup --prune` daily at `OPS_BACKUP_TIME` (02:30) with 30-day retention; pruning always keeps
  the newest backup (a stopped schedule never leaves zero backups).
- `ops:restore {backup}`: decrypts, verifies every entry checksum and the compatibility BEFORE touching anything,
  then (only with `--force` AND the target database name typed or passed as `--confirm=`) drops the target tables,
  recreates them from the DDL, loads rows with foreign keys off, writes the files back and **recomputes every table
  checksum against the manifest** (failure = exit 1). `--verify-only` changes nothing. Refused: another engine,
  another deployment id (unless `--allow-other-deployment` for a move), and **a backup from a newer schema** (a
  migration the code does not know). An older backup restores, then `migrate` brings it forward.
- Audit: `ops.backup_created` / `ops.backup_restored` (area `operations`) when the target is the default connection.
- Tenant isolation: every read/write goes through the named connection and disks only; the drill test runs two
  deployments as two connections and two disks and proves B's checksums and files are unchanged.

### 2. Upgrade safety: expand/contract markers and `ops:preflight`

- Every new migration carries `#[App\Operations\Upgrade\Expand]` (adds only; the previous release keeps working) or
  `#[Contract]` (drops/renames/tightens; ships at least one release after the code stopped using it). A test fails
  for an unmarked migration from this ADR on, or for an "expand" whose `up()` drops, renames or `change()`s.
- After every `migrate`, `ops_migration_kinds` records each ran migration's kind, so an OLDER release (whose code no
  longer has the file) can still tell an expanded schema from a contracted one.
- `ops:preflight` (exit 1 on any fail, `--json` for scripts) checks: schema (unknown ran migrations must all be
  expand), pending migrations (marked, not mis-marked), maintenance (a pending contract requires `artisan down`),
  queues drained (`ops.preflight.queues`), a backup newer than the RPO (24 h) when migrations are pending, and the
  license status (info). A fresh database passes.
- `manage.sh deploy` does not call it yet (infra file): proposed in `docs/proposals/manage-sh-operations.md`; until
  then the runbook makes it a manual gate.

### 3. Health

- `GET /health` (outside the web group: no session, cookies or CSRF; `throttle:60,1`; `Cache-Control: no-store`)
  returns `{"status":"ok|fail","checks":{database, redis?, scheduler, queue?}}` with one word per check
  (`ok|fail|stale`), 200 or 503. Redis is checked only if cache/queue/session use it; the queue only if it is not
  `sync`. `ops:heartbeat` (scheduler, every minute) stamps the scheduler beat and queues `QueueHeartbeat`, which a
  worker stamps. Stale after 180 s (scheduler) / 300 s (queue). Laravel's `/up` stays as a liveness probe.

### 4. License states inside the app

- The shared `license` prop now carries `mode` (`full` / `no_new_work` / `read_only`) and `blocked`: the main add
  actions the user holds (`orders_create` P-15, `customers_create` P-09, `team_invite` P-05, `branches_create` P-03,
  `store_links` P-92) mapped to why they are unavailable — `license_no_new_work`, `license_read_only`, `license`,
  `seats_limit`, `branches_limit` (`Access\LicenseBlocks`). Screens show these actions disabled with the reason
  (`BlockedAction`) instead of hiding them; the Gate and `LicenseLimits` still refuse on the server.
- **Reading in read-only phases:** the Gate's phase step blocked pure reading where a screen's only permission is a
  write permission (customers via P-09/P-10, branches via P-03). `Authorizer::inspectForReading()` / `canRead()` /
  `grantsFor(..., reading: true)` run the same evaluation without the phase step and are used ONLY for listing and
  showing (customer list/detail/interests, branch list); every change still uses `can()`/`authorize()`.
- Downgrade/renewal (SUB-12/13): seats or branches at/over the new limit block new invitations, reactivations and
  branches; nothing is disabled or deleted, and members over the limit keep working.

### 5. Facility closure (Q-53 a, FIN-12, NFR-16)

- `settings/closure` (owner membership AND P-62 through the Gate; available in read-only phases): readiness computed
  from the sources every time — open orders, unpaid dues (`OpenDues`, bounded), payments awaiting confirmation,
  proofs under review, refunds not paid out, open disputes, unpaid commission payouts, open settlement follow-ups,
  open returns, active stock reservations, open purchase orders. It never closes, refunds or deletes anything.
- Full export: `BuildFacilityExport` on the queue (one at a time) writes a ZIP to the private `local` disk — one CSV
  per table under its domain folder (UTF-8 BOM, formula-injection neutralized), the private files under `files/`, and
  a README with rows and sha256 per CSV. Never exported: credentials, 2FA secrets, tokens and their hashes, sessions,
  OTP challenges, idempotency keys, license state, queue internals, outbox payloads, encrypted provider settings.
  Download through a 30-minute signed route that re-checks the owner; the file expires after 24 h
  (`ops:exports-prune`, hourly). `ops.export_requested` / `ops.export_downloaded` are audited.
- The ZIP itself is not encrypted: it is served only over the signed, re-authorized link and deleted after 24 h.

## Consequences

- Server admin decisions (Q-54, NOT decided here): RPO/RTO values, off-server target and credentials, retention,
  where the second copy of `OPS_BACKUP_KEY` lives. `backups_s3` needs `league/flysystem-aws-s3-v3` installed (a
  separate, approved dependency change) — until then backups stay on the server's private disk.
- Restore replaces the whole database of the target connection; run it only in maintenance mode, with the worker
  stopped, and never against a database you did not intend (typed confirmation).
- New migrations must carry a marker; reviewers check that contract migrations follow an expand release.
