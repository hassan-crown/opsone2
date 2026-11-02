<?php

namespace App\Site\Theme;

use App\Models\Site\SiteTheme;
use Illuminate\Http\Request;

/**
 * Q-03 (a), decided by the product owner (ADR-0035): the facility's applied theme colours reach the facility-facing
 * surfaces only — the public site, the store and the customer portal. The portal is an Inertia page in the shared
 * shell, so it gets the live palette scoped to `[data-brand='facility']`; every staff, manager and admin screen keeps
 * OpsOne's own identity (logo, colours, fonts) and never receives it. Drafts never leak here (live settings only).
 */
final class PortalBrand
{
    public const SCOPE = "[data-brand='facility']";

    /** The portal's routes (`customer.*`, ADR-0022); the store and site render their own Blade layouts. */
    public static function applies(Request $request): bool
    {
        return $request->routeIs('customer.*');
    }

    /** The scoped `<style>` body for this request; empty outside the portal or with the default theme. */
    public static function css(Request $request): string
    {
        if (! self::applies($request)) {
            return '';
        }
        // Read only: rendering a page never creates the theme row.
        $theme = SiteTheme::query()->orderBy('id')->first();

        return $theme === null ? '' : ThemeSettings::css(ThemeSettings::published($theme), self::SCOPE, fonts: false);
    }
}
