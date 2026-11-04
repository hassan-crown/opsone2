<?php

return [

    /*
    | Checkout code limits against SMS pumping (pre-launch audit M-4). Codes go only to mobiles whose calling code is in
    | `country_codes` (comma separated, default Saudi Arabia), and the whole store sends at most `per_hour` / `per_day`
    | codes whatever the address; the owner is notified at 80% of either cap.
    */
    'otp' => [
        'country_codes' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORE_OTP_COUNTRY_CODES', '966'))))),
        'per_hour' => (int) env('STORE_OTP_PER_HOUR', 60),
        'per_day' => (int) env('STORE_OTP_PER_DAY', 300),
    ],

];
