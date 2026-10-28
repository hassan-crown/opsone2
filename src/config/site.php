<?php

/*
 * Public site (P8 part A, ADR-0021).
 */
return [

    // Zones a facility may never claim as its custom domain (the OpsOne platform and local names).
    'reserved_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('SITE_RESERVED_DOMAINS', 'opsone.sa,opsone.app,localhost,example.com,example.org,example.net,test,local,internal'))))),

    // Lead form: the shortest time a human needs to fill it, and the per-IP limits (honeypot + throttle, no captcha).
    'lead' => [
        'min_seconds' => (int) env('SITE_LEAD_MIN_SECONDS', 3),
        'max_seconds' => (int) env('SITE_LEAD_MAX_SECONDS', 7200),
        'per_ip_per_hour' => (int) env('SITE_LEAD_PER_HOUR', 5),
        'per_ip_per_day' => (int) env('SITE_LEAD_PER_DAY', 20),
    ],

];
