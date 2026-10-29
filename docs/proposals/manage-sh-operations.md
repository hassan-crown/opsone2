# Proposal: wire operations into `manage.sh` and Compose (not applied)

> Status: proposal for the server admin / infrastructure owner. Root infrastructure files were NOT changed in P9c
> (`.claude/rules/infrastructure.md`). Decision context: `docs/decisions/0028-operations.md`, procedures: `docs/runbook.md`.

## 1. Preflight gate in `deploy` (NFR-15)

Today `deploy` pulls, builds, runs the installer (which migrates) and builds assets with no compatibility check.
Proposed change in `manage.sh` → `deploy)`, right after `git pull --ff-only` and the image build, before the installer:

```bash
    echo ">>> [${ENV_LOWER}] Upgrade preflight..."
    if ! dc run --rm --no-deps app php artisan ops:preflight; then
      echo 'ERROR: ops:preflight refused this release (see the table above). Nothing was migrated.' >&2
      exit 1
    fi
```

Notes:
- Runs the NEW code against the CURRENT database, so it sees the pending migrations and refuses unmarked, mis-marked
  or contract-without-maintenance changes, a busy queue and a missing fresh backup.
- A failing preflight leaves the old containers running the old code only if the image tag is not reused; with the
  current bind-mounted source tree the pulled code is already in place, so a refusal must be followed by
  `git checkout <previous tag>` (runbook §4). This is another reason to move to immutable images.
- Optional flag `--skip-backup-check` must never be the default.

## 2. Backup before migrating

Same place, before the installer, when the preflight reports pending migrations:

```bash
    dc run --rm --no-deps app php artisan ops:backup
```

## 3. Container health check on `/health`

`docker-compose.yml` `web` service could use the app's probe instead of a TCP check:

```yaml
    healthcheck:
      test: ["CMD-SHELL", "wget -qO- --header='Host: ${WEB_DOMAIN}' http://127.0.0.1/health >/dev/null || exit 1"]
      interval: 60s
      timeout: 5s
      retries: 3
```

`/health` returns 503 when the scheduler or worker heartbeat is stale, so this marks the stack unhealthy when jobs
stop, not only when nginx is down. The `Host` header must be a trusted host (ADR-0025).

## 4. Off-server backup target (Q-54)

- Install `league/flysystem-aws-s3-v3` (separate dependency change), set `OPS_BACKUP_DISK=backups_s3` and the
  `OPS_BACKUP_S3_*` values per facility (one bucket prefix + one credential pair per facility, write-only if the
  provider supports it, lifecycle rule ≥ retention).
- Alternative without the package: keep `backups` local and add a host cron that copies
  `src/storage/app/backups/opsone/` to the off-server target (`rclone copy`, files are already encrypted).

## 5. Restore drill database

A documented, scripted way for the admin to create and drop a throw-away drill database per facility
(e.g. `opsone_<facility>_drill`) with a grant for the app user, so `ops:restore --force --confirm=<drill>` never needs
the production database name.
