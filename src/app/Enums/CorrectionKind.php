<?php

namespace App\Enums;

/**
 * M-11-T07 (FIN-08): `void` takes back a payment recorded by mistake; `amend` takes it back and re-enters the right
 * amount, date, method or reference as a new payment with its own receipt.
 */
enum CorrectionKind: string
{
    case Void = 'void';
    case Amend = 'amend';
}
