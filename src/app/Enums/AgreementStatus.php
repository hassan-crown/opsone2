<?php

namespace App\Enums;

/**
 * An agreement version: the active one, or an earlier one kept unchanged after a change was applied.
 */
enum AgreementStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
}
