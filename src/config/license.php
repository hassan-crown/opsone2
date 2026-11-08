<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signed license (ADR-0004)
    |--------------------------------------------------------------------------
    |
    | The tenant app only verifies licenses signed by the central app. It holds
    | the public key; the private key never exists in this deployment.
    |
    */

    'path' => env('LICENSE_PATH', storage_path('app/private/license.json')),

    // Base64 Ed25519 public key (32 bytes) and the key id the central app signs with.
    'public_key' => env('LICENSE_PUBLIC_KEY'),

    'key_id' => env('LICENSE_KEY_ID', 'opsone-production'),

    // Extra trusted keys for rotation overlap: "kid=base64,kid=base64" (central-app plan §4.3).
    // Trusted alongside the pair above; malformed entries are ignored. Remove a key once retired.
    'public_keys' => env('LICENSE_PUBLIC_KEYS'),

    // When set, the license's deployment_id must match it.
    'deployment_id' => env('LICENSE_DEPLOYMENT_ID'),

    // A clock this far behind the last seen time is untrusted (M-19-T05).
    'clock_tolerance_seconds' => (int) env('LICENSE_CLOCK_TOLERANCE', 300),

    'heartbeat_seconds' => 60,

    'cache_key' => 'license.state',

    // Renewal notices start this many days before the term ends (domain 08: 14 / 7 / 0).
    'renewal_notice_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | License sync with the central app (ADR-0047)
    |--------------------------------------------------------------------------
    |
    | `license:sync` pulls the latest signed license every hour. Needs all three
    | of: the sync base URL, this deployment's sync secret key (generated here
    | with `license:sync-keygen`, never the license key) and LICENSE_DEPLOYMENT_ID.
    | Unset = sync off; manual `license:install` keeps working either way.
    |
    */

    'sync' => [
        // Base URL, e.g. https://sync.example.com — the client posts to {url}/v1/sync. HTTPS outside local/testing.
        'url' => env('LICENSE_SYNC_URL'),

        // Base64 Ed25519 secret key (64 bytes) of this deployment's sync keypair. Secret: never print or commit it.
        'secret_key' => env('LICENSE_SYNC_SECRET_KEY'),

        'connect_timeout_seconds' => 5,

        'timeout_seconds' => 15,

        // The scheduled run waits a random 0..N seconds so deployments do not all call at the top of the hour.
        'jitter_seconds' => 600,

        'status_key' => 'license.sync',
    ],

];
