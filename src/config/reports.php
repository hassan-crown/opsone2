<?php

/*
 * Reports and exports (SHR-12, ADR-0026). Exports are private files on the attachments disk, kept briefly.
 */
return [

    // How long a generated export stays downloadable, in hours; a daily command removes the file afterwards.
    'export_retention_hours' => (int) env('REPORTS_EXPORT_RETENTION_HOURS', 24),

    // Lifetime of a signed download link, in minutes. The link is bound to the user it was issued for.
    'export_link_ttl_minutes' => (int) env('REPORTS_EXPORT_LINK_TTL', 5),

    // Exports a user may request per hour.
    'export_rate_per_hour' => (int) env('REPORTS_EXPORT_RATE', 20),
];
