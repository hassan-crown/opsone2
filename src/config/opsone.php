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

];
