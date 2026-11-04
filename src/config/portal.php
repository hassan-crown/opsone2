<?php

return [

    /*
    | Fixed demo sign-in / e-signature code for demos and trials only. Empty = disabled. Honoured solely in the
    | environments listed below; anywhere else it is ignored and a boot-time warning (without the value) is logged.
    | It never replaces the real code flow: the normal message is still sent and every cap still applies. It opens only
    | the showcase accounts: customers whose email is on `demo_otp_email_domain` (a reserved `.test` name, M-5); every
    | other customer needs the real code. `ops:preflight` and the installer fail when it is set outside these environments.
    */
    'demo_otp' => env('PORTAL_DEMO_OTP'),

    'demo_otp_environments' => ['local', 'testing', 'demo'],

    'demo_otp_email_domain' => 'mail-demo.test',

];
