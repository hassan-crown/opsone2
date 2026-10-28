<?php

namespace App\Tasks;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Task;
use App\Models\User;
use App\Support\PropRows;
use App\Workflow\AppointmentBooking;
use App\Workflow\TaskQueries;
use Illuminate\Support\Collection;

/**
 * The lists behind `TaskList`, the home cards and `ExecHomeMobile`. Everything starts from TaskQueries, which
 * re-checks P-24 on every row, so counts and rows never include work outside the user's scope.
 */
final class TaskViews
{
    public const VIEWS = ['all', 'mine', 'inbox', 'waiting', 'followed', 'completed'];

    /**
     * @return array{mine: Collection<int, Task>, inbox: Collection<int, Task>, followed: Collection<int, Task>, completed: Collection<int, Task>}
     */
    public static function load(User $user): array
    {
        return [
            'mine' => self::sorted(TaskQueries::mine($user)),
            'inbox' => self::sorted(TaskQueries::inbox($user)),
            'followed' => self::sorted(TaskQueries::followed($user)),
            'completed' => TaskQueries::completed($user),
        ];
    }

    /**
     * @param  array{mine: Collection<int, Task>, inbox: Collection<int, Task>, followed: Collection<int, Task>, completed: Collection<int, Task>}  $sets
     * @return Collection<int, Task>
     */
    public static function pick(array $sets, string $view): Collection
    {
        return match ($view) {
            'mine' => $sets['mine'],
            'inbox' => $sets['inbox'],
            'waiting' => $sets['mine']->filter(fn (Task $t) => $t->status === TaskStatus::Waiting)->values(),
            'followed' => $sets['followed'],
            'completed' => $sets['completed'],
            default => $sets['mine']->concat($sets['inbox'])->concat($sets['followed'])->unique('id')->pipe(fn ($c) => self::sorted($c)),
        };
    }

    /**
     * @param  array{mine: Collection<int, Task>, inbox: Collection<int, Task>, followed: Collection<int, Task>, completed: Collection<int, Task>}  $sets
     * @return array<string, int>
     */
    public static function counts(array $sets): array
    {
        return [
            'all' => self::pick($sets, 'all')->count(),
            'mine' => $sets['mine']->count(),
            'inbox' => $sets['inbox']->count(),
            'waiting' => self::pick($sets, 'waiting')->count(),
            'followed' => $sets['followed']->count(),
            'completed' => $sets['completed']->count(),
        ];
    }

    /**
     * Overdue first, then the nearest due date, work with no date last.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, Task>
     */
    public static function sorted(Collection $tasks): Collection
    {
        return $tasks->sortBy(fn (Task $t) => [$t->due_at === null ? PHP_INT_MAX : $t->due_at->getTimestamp(), $t->id])->values();
    }

    /**
     * `ExecHomeMobile`: my open work split into today (incl. overdue) and upcoming, each with the visit it is booked on.
     *
     * @param  array{mine?: Collection<int, Task>, inbox?: Collection<int, Task>}  $loaded  sets the caller already read (home)
     * @return array{today: list<object>, upcoming: list<object>, inbox: int, waiting: int}
     */
    public static function execHome(User $user, array $loaded = []): array
    {
        $all = self::sorted($loaded['mine'] ?? TaskQueries::mine($user));
        $mine = $all->filter(fn (Task $t) => $t->status !== TaskStatus::Waiting);
        $endOfToday = now(AppointmentBooking::timezone())->endOfDay();
        $visits = Appointment::query()->whereIn('task_id', $mine->pluck('id'))->whereNotIn('status', ['cancelled', 'done'])
            ->orderBy('starts_at')->get()->groupBy('task_id');

        $shape = function (Task $t) use ($user, $visits) {
            $visit = $visits->get($t->id)?->first();

            return TaskPresenter::row($t, $user) + ['visit' => $visit === null ? null : [
                'starts_at' => $visit->starts_at->toIso8601String(),
                'address' => $visit->address,
                'maps_url' => $visit->mapsUrl(),
            ]];
        };

        $today = $mine->filter(fn (Task $t) => $t->due_at !== null && $t->due_at->lte($endOfToday));

        return [
            'today' => PropRows::of($today->map($shape)),
            'upcoming' => PropRows::of($mine->reject(fn (Task $t) => $today->contains('id', $t->id))->take(10)->map($shape)),
            'inbox' => ($loaded['inbox'] ?? TaskQueries::inbox($user))->count(),
            'waiting' => $all->count() - $mine->count(),
        ];
    }

    /**
     * Home cards (SHR-01): counts and the next few rows, scoped like the lists.
     *
     * @param  array{mine?: Collection<int, Task>, inbox?: Collection<int, Task>}  $loaded  sets the caller already read (home)
     * @return array<string, array<string, mixed>>
     */
    public static function homeCards(User $user, array $loaded = []): array
    {
        $cards = [];
        $mine = self::sorted($loaded['mine'] ?? TaskQueries::mine($user));
        $open = $mine->filter(fn (Task $t) => $t->status !== TaskStatus::Waiting);
        $inbox = $loaded['inbox'] ?? TaskQueries::inbox($user);
        $cards['my_tasks'] = [
            'count' => $mine->count(),
            'overdue' => $mine->filter(fn (Task $t) => $t->due_at !== null && $t->due_at->isPast())->count(),
            'waiting' => $mine->count() - $open->count(),
            'inbox' => $inbox->count(),
            'items' => $open->take(3)->map(fn (Task $t) => TaskPresenter::row($t, $user))->values()->all(),
        ];

        if (Access::can($user, Permission::P22)) {
            $unassigned = self::pick(['mine' => collect(), 'inbox' => $inbox, 'followed' => TaskQueries::followed($user), 'completed' => collect()], 'all');
            $cards['department_inbox'] = [
                'count' => $unassigned->count(),
                'items' => $unassigned->take(3)->map(fn (Task $t) => TaskPresenter::row($t, $user))->values()->all(),
            ];
        }

        return $cards;
    }
}
