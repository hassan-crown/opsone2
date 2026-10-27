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

    // When set, the license's deployment_id must match it.
    'deployment_id' => env('LICENSE_DEPLOYMENT_ID'),

    // A clock this far behind the last seen time is untrusted (M-19-T05).
    'clock_tolerance_seconds' => (int) env('LICENSE_CLOCK_TOLERANCE', 300),

    'heartbeat_seconds' => 60,

    'cache_key' => 'license.state',

    // Renewal notices start this many days before the term ends (domain 08: 14 / 7 / 0).
    'renewal_notice_days' => 14,

];
