<?php

namespace App\Returns;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Return notifications through the notifier / outbox (ADR-0007), after commit so a rolled-back write never tells
 * anyone: a new request → P-102 holders on the order; an acceptance → P-53 holders (goods to receive); an inspection
 * that leaves money to settle → P-42 holders. The actor is not told about their own action; each notice is sent once
 * per return and event (dedupe key). Opening one re-checks access on the return.
 */
final class ReturnNotices
{
    public function __construct(private readonly Notifier $notifier) {}

    public function later(string $type, OrderReturn $return, Permission $permission, User $actor): void
    {
        $returnId = $return->id;
        $actorId = $actor->id;
        DB::afterCommit(fn () => $this->send($type, $returnId, $permission, $actorId));
    }

    public function send(string $type, int $returnId, Permission $permission, int $actorId): void
    {
        $return = OrderReturn::query()->with('order')->find($returnId);
        if ($return === null) {
            return;
        }
        $userIds = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');
        foreach (User::query()->whereIn('id', $userIds)->where('is_support', false)->whereKeyNot($actorId)->get() as $user) {
            if (! Access::can($user, $permission, $return)) {
                continue;
            }
            $this->notifier->send($user, $type, ['number' => $return->number, 'order' => $return->order->number], $return, $type.':'.$return->id);
        }
    }
}
