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
}
