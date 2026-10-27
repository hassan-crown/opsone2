<?php

return [

    /*
    | Provisioning placeholders for the single facility of this deployment (ADR-0002). Real values come from
    | the subscription request during provisioning; the owner gets an unknown password and uses «forgot password».
    */

    'facility' => [
        'name_ar' => env('OPSONE_FACILITY_NAME_AR', env('APP_NAME', 'OpsOne')),
        'name_en' => env('OPSONE_FACILITY_NAME_EN'),
        'currency' => env('OPSONE_FACILITY_CURRENCY', 'SAR'),
        'timezone' => env('OPSONE_FACILITY_TIMEZONE', 'Asia/Riyadh'),
    ],

    'owner' => [
        'email' => env('OPSONE_OWNER_EMAIL'),
        'name' => env('OPSONE_OWNER_NAME', 'Owner'),
    ],

    /*
    | OpsOne support (SHR-14). Support requests are mailed to this address through the outbox; without it they
    | stay stored here with status «pending». Support access itself is the owner's separate grant (M-38).
    */
    'support' => [
        'email' => env('OPSONE_SUPPORT_EMAIL'),
        // Longest window an owner may grant, and how long an undecided request waits, in hours.
        'max_access_hours' => (int) env('OPSONE_SUPPORT_MAX_ACCESS_HOURS', 72),
        'request_ttl_hours' => (int) env('OPSONE_SUPPORT_REQUEST_TTL_HOURS', 72),
    ],

];
