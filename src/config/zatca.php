<?php

/*
| ZATCA (Fatoora) e-invoicing (ADR-0033). No credential lives here or in the repository: CSIDs are obtained by the
| owner's onboarding (portal OTP) and stored encrypted in the database. The environment only limits which ZATCA
| environments the owner may choose and where the gateway is.
*/
return [
    // Comma-separated environments the owner may pick: fake (in-process, tests/demo), simulation, production.
    // simulation/production talk to the real gateway, so they must be listed explicitly per deployment.
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('ZATCA_ENVIRONMENTS', 'fake'))))),

    'simulation' => [
        'base_url' => env('ZATCA_SIMULATION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
    ],

    'production' => [
        'base_url' => env('ZATCA_PRODUCTION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
    ],

    'timeout' => (int) env('ZATCA_TIMEOUT', 20),
    'connect_timeout' => (int) env('ZATCA_CONNECT_TIMEOUT', 5),

    // EGS identity written into the CSR serial (1-solution|2-model|3-unit).
    'solution_name' => env('ZATCA_SOLUTION_NAME', 'OpsOne'),
    'solution_version' => env('ZATCA_SOLUTION_VERSION', '1.0'),
];
