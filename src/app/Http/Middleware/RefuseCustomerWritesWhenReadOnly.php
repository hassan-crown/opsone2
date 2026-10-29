<?php

namespace App\Http\Middleware;

use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\LicenseMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer and public writes (portal decisions, proofs, appointments, cancellations, tickets; the site lead form) in
 * the read-only license phase (SUB-12, ADR-0028): staff can only read and export then, so nobody could act on what a
 * customer sends. Customers keep reading their workspace and signing in or out. Wind-down still accepts them: the
 * facility is completing existing work.
 */
class RefuseCustomerWritesWhenReadOnly
{
    public function __construct(private readonly LicenseCapabilities $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->license->mode() === LicenseMode::ReadOnly, 403, __('access.denied.license_read_only'));

        return $next($request);
    }
}
