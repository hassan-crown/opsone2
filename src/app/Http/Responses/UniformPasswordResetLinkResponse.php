<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Answers a reset-link request for an unknown or throttled email exactly like a sent link, so the
 * forgot-password form cannot be used to find out which emails have an account.
 */
class UniformPasswordResetLinkResponse extends SuccessfulPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        $this->status = Password::RESET_LINK_SENT;

        return parent::toResponse($request);
    }
}
