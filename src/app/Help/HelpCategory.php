<?php

namespace App\Help;

/**
 * Help center sections (HelpCenter screen). Labels are translation keys `help.category.{value}`.
 */
enum HelpCategory: string
{
    case GettingStarted = 'getting_started';
    case DailyWork = 'daily_work';
    case Settings = 'settings';
    case Faq = 'faq';

    /** «My guide»: the walkthrough of one role (ADR-0048); listed on its own page, not in the help center sections. */
    case RoleGuide = 'role_guide';
}
