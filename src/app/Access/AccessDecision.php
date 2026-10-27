<?php

namespace App\Access;

use App\Access\Enums\DenyReason;
use Illuminate\Auth\Access\Response;

final readonly class AccessDecision
{
    private function __construct(
        public bool $allowed,
        public ?DenyReason $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(DenyReason $reason): self
    {
        return new self(false, $reason);
    }

    public function toResponse(): Response
    {
        return $this->allowed
            ? Response::allow()
            : Response::deny(__('access.denied.'.$this->reason?->value), $this->reason?->value);
    }
}
