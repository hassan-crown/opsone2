<?php

/*
| WhatsApp channel for customer notifications (ADR-0030). Everything here comes from the environment — no
| credential is stored in the database, the repository or the UI. The facility only switches the channel on and
| chooses which events send (integration_settings, key `whatsapp`, no secrets).
*/
return [
    // log (local default, records metadata only) | fake (tests) | cloud (WhatsApp Cloud API by Meta)
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'cloud' => [
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        // Signs webhook bodies (X-Hub-Signature-256). Without it every webhook is refused.
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // Echoed back once, when Meta verifies the webhook URL (hub.verify_token).
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
        'connect_timeout' => (int) env('WHATSAPP_CONNECT_TIMEOUT', 5),
        // Immediate in-request retries on connection errors and 5xx only; further retries belong to the outbox.
        'retries' => (int) env('WHATSAPP_RETRIES', 2),
    ],

    // Approved template names at Meta. One name per event; the language code (ar / en) picks the translation.
    // Override a name per environment with WHATSAPP_TEMPLATE_<EVENT>, e.g. WHATSAPP_TEMPLATE_QUOTE_READY.
    'template_prefix' => env('WHATSAPP_TEMPLATE_PREFIX', 'opsone_'),

    /*
    | WhatsApp inbox (AI-3, ADR-0030 amendment): the facility's own Meta number (Q-72) receives customers' messages.
    | Received media is checked against this allow-list (declared type, size, and the downloaded bytes) before it is
    | stored as a private attachment; anything else is recorded as «rejected» and never downloaded.
    */
    'inbox' => [
        'max_media_kb' => (int) env('WHATSAPP_INBOX_MAX_MEDIA_KB', 16384),
        // MIME type => stored extension.
        'media_types' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/amr' => 'amr',
            'video/mp4' => 'mp4',
        ],
    ],
];
