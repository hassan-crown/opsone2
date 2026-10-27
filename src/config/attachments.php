<?php

/*
 * Private attachments (SHR-10, NFR-14, ADR-0006). Files live on a private disk inside the tenant deployment (Q-23);
 * switching ATTACHMENTS_DISK to an S3-compatible disk later needs no code change.
 */
return [

    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    // Lifetime of a signed download link, in minutes. The link is also bound to the user it was issued for.
    'link_ttl_minutes' => (int) env('ATTACHMENTS_LINK_TTL', 5),

    // Largest single file, in kilobytes.
    'max_kb' => (int) env('ATTACHMENTS_MAX_KB', 10240),

    /*
     * Allowlist: extension => accepted detected MIME types and the leading bytes the content must start with.
     * Anything else (scripts, executables, archives, SVG, HTML, Office macros) is refused.
     */
    'types' => [
        'pdf' => ['mimes' => ['application/pdf'], 'magic' => ['%PDF-']],
        'png' => ['mimes' => ['image/png'], 'magic' => ["\x89PNG\r\n\x1a\n"]],
        'jpg' => ['mimes' => ['image/jpeg'], 'magic' => ["\xFF\xD8\xFF"]],
        'jpeg' => ['mimes' => ['image/jpeg'], 'magic' => ["\xFF\xD8\xFF"]],
        'webp' => ['mimes' => ['image/webp'], 'magic' => ['RIFF']],
        'docx' => [
            'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'magic' => ["PK\x03\x04"],
        ],
        'xlsx' => [
            'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'magic' => ["PK\x03\x04"],
        ],
    ],

];
