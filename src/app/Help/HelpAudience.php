<?php

namespace App\Help;

/**
 * Readers of a help article: facility staff (the app) or customers (the portal). Each side only ever sees its own.
 * Mirrors the folder under resources/help/{locale}/{audience}.
 */
enum HelpAudience: string
{
    case Staff = 'staff';
    case Customer = 'customer';
}
