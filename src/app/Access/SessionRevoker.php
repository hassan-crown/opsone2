<?php

namespace App\Access;

use App\Models\User;
use App\Support\RequestCache;
use Illuminate\Contracts\Session\Session;
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
        $this->forgetVersions();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->delete();
        }
    }

    /**
     * Revokes every other session of the user (any session driver) and keeps the current one signed in by
     * re-stamping it with the new access version. Returns how many stored sessions were deleted.
     */
    public function revokeOthers(User $user, Session $current): int
    {
        User::query()->whereKey($user->id)->update([
            'access_version' => DB::raw('access_version + 1'),
            'remember_token' => Str::random(60),
        ]);
        $this->forgetVersions();
        $current->put('access_version', (int) User::query()->whereKey($user->id)->value('access_version'));

        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $current->getId())
            ->delete();
    }

    /**
     * The Gate caches access versions for a moment (ADR-0027); a revocation in this process is seen at the next check.
     */
    private function forgetVersions(): void
    {
        if (app()->resolved(Authorizer::class)) {
            app(Authorizer::class)->flush();
        }
        if (app()->resolved(RequestCache::class)) {
            app(RequestCache::class)->flush();
        }
    }
}
