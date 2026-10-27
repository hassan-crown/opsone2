<?php

namespace App\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Revokes every existing session of a user immediately (matrix: «تغيير الصلاحيات يطبق على الجلسات الحالية»).
 *
 * - bumps `users.access_version`; EnsureAccessIsCurrent logs out any session holding an older stamp;
 * - rotates the remember-me token so a "remember me" cookie cannot silently re-authenticate;
 * - deletes stored session rows when the database session driver is used.
 */
final class SessionRevoker
{
    public function revoke(int $userId): void
    {
        User::query()->whereKey($userId)->update([
            'access_version' => DB::raw('access_version + 1'),
            'remember_token' => Str::random(60),
        ]);

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->delete();
        }
    }
}
