<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The first owner's invitation (ORG «أول دخول»), created by provisioning. Valid for seven days; only the token
 * hash is stored. Accepting it needs the emailed ownership-proof code (Q-17) and creates the owner account.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string $token_hash
 * @property InvitationStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $code_hash
 * @property Carbon|null $code_sent_at
 * @property Carbon|null $code_expires_at
 * @property int $code_attempts
 */
#[Fillable(['email', 'name', 'token_hash', 'expires_at'])]
#[Hidden(['token_hash', 'code_hash'])]
class OwnerInvitation extends Model
{
    protected $attributes = [
        'status' => 'pending',
        'code_attempts' => 0,
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'code_sent_at' => 'datetime',
            'code_expires_at' => 'datetime',
        ];
    }
}
