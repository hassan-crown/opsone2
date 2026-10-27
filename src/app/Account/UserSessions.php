<?php

namespace App\Account;

use App\Access\SessionRevoker;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's own sessions (P-84): list devices and sign out the others.
 * Only the database session driver can enumerate sessions; other drivers report `supported() === false`.
 */
final class UserSessions
{
    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return list<array{id: string, device: string, browser: string, platform: string, ip: ?string, last_active_at: string, current: bool}>
     */
    public function for(User $user, string $currentId): array
    {
        if (! $this->supported()) {
            return [];
        }

        $sessions = [];

        foreach ($this->table()->where('user_id', $user->id)->orderByDesc('last_activity')->get() as $row) {
            $agent = (string) ($row->user_agent ?? '');

            $sessions[] = [
                'id' => (string) $row->id,
                'device' => $this->device($agent),
                'browser' => $this->browser($agent),
                'platform' => $this->platform($agent),
                'ip' => is_string($row->ip_address) ? $row->ip_address : null,
                'last_active_at' => now()->setTimestamp((int) $row->last_activity)->toIso8601ZuluString(),
                'current' => (string) $row->id === $currentId,
            ];
        }

        return $sessions;
    }

    /**
     * End every other session of the user and rotate the remember-me token; the current one stays.
     * Uses the access-version stamp too, so other sessions end even without the database session driver.
     */
    public function endOthers(User $user, Session $current): int
    {
        return app(SessionRevoker::class)->revokeOthers($user, $current);
    }

    /**
     * End one other session of the user (never the current one). False when it is not theirs.
     */
    public function end(User $user, string $sessionId, string $currentId): bool
    {
        if (! $this->supported() || $sessionId === $currentId) {
            return false;
        }

        return $this->table()->where('user_id', $user->id)->where('id', $sessionId)->delete() > 0;
    }

    private function table(): Builder
    {
        return DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'));
    }

    private function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet/i', $agent) => 'tablet',
            (bool) preg_match('/Mobile|iPhone|Android/i', $agent) => 'mobile',
            default => 'desktop',
        };
    }

    private function browser(string $agent): string
    {
        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => '',
        };
    }

    private function platform(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iOS/i', $agent) => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };
    }
}
