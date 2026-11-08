<?php

namespace App\Licensing;

/**
 * Display-only notice a license may carry (`notice.kind`, ADR-0004 amendment T3). Closed set: an unknown kind is
 * ignored like any unknown field of schema 1, never rejected. It changes no phase, capability or limit.
 */
enum LicenseNotice: string
{
    /** The central app froze the facility: the license is issued already expired (Q-83). */
    case AdministrativeSuspension = 'administrative_suspension';
}
