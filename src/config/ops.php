<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operations for this single-tenant deployment (ADR-0028)
    |--------------------------------------------------------------------------
    |
    | Backups, restore, upgrade preflight, health and facility closure export.
    | Every value here belongs to THIS deployment only (ADR-0002): its own
    | database connection, its own private files and its own backup key.
    |
    */

    // Release identifier written into backup manifests and shown by ops:preflight.
    'version' => env('APP_VERSION', 'dev'),

    'backup' => [
        // Run the daily scheduled backup (Q-54 default: daily, kept 30 days).
        'enabled' => (bool) env('OPS_BACKUP_ENABLED', true),

        'time' => env('OPS_BACKUP_TIME', '02:30'),

        // Base64 32-byte key (`php artisan ops:backup-key` prints a new one). Never the APP_KEY.
        'key' => env('OPS_BACKUP_KEY'),

        // Where encrypted backups go. Off-server storage (an S3-compatible bucket) is the server admin's choice (Q-54).
        'disk' => env('OPS_BACKUP_DISK', 'backups'),

        'directory' => env('OPS_BACKUP_DIRECTORY', 'opsone'),

        'retention_days' => (int) env('OPS_BACKUP_RETENTION_DAYS', 30),

        // Private file disks included in a backup and written back on restore.
        'file_disks' => ['attachments'],

        // Tables whose structure is kept but whose rows are transient (sessions, cache, queued jobs, exports).
        'skip_data' => ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'facility_exports'],
    ],

    'preflight' => [
        // Queues that must be empty before an upgrade.
        'queues' => ['default'],

        // RPO (Q-54): the latest backup must be at most this old before migrating.
        'max_backup_age_hours' => (int) env('OPS_PREFLIGHT_MAX_BACKUP_AGE_HOURS', 24),
    ],

    'health' => [
        'scheduler_max_age_seconds' => 180,
        'queue_max_age_seconds' => 300,
    ],

    'export' => [
        'disk' => 'local',
        'directory' => 'exports',
        // The ready ZIP and its download link expire after this many hours.
        'ttl_hours' => 24,
        'link_minutes' => 30,
    ],
];
