<?php

namespace App\Licensing;

/**
 * Why a license could not be trusted (M-19-T05).
 */
enum LicenseProblem: string
{
    case Missing = 'missing';
    case Unreadable = 'unreadable';
    case Corrupt = 'corrupt';
    case UnknownKey = 'unknown_key';
    case BadSignature = 'bad_signature';
    case UnsupportedSchema = 'unsupported_schema';
    case InvalidPayload = 'invalid_payload';
    case DomainMismatch = 'domain_mismatch';
    case DeploymentMismatch = 'deployment_mismatch';
    case Superseded = 'superseded';
    case ClockUntrusted = 'clock_untrusted';
}
