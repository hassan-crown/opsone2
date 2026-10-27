<?php

namespace App\Http\Controllers\Audit;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\AuditAction;
use App\Audit\AuditQuery;
use App\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * `AuditLog` (SHR-11, P-63): read-only, paginated on the server, limited to the viewer's P-63 scope. There is no
 * route that edits or deletes an event. `?event=` opens the detail drawer, re-authorized for that one event.
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly Authorizer $access) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->access->authorize($user, Permission::P63);

        $filters = $this->filters($request);
        $visible = fn (): Builder => AuditQuery::visibleTo($this->access->grantsFor($user, Permission::P63));

        $events = $this->applyFilters($visible(), $filters)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditEvent $e) => $this->row($e));

        $actors = $visible()->whereNotNull('actor_user_id')
            ->select(['actor_user_id', 'actor_name'])->distinct()->orderBy('actor_name')->limit(200)->get()
            ->map(fn (AuditEvent $e) => ['id' => (int) $e->actor_user_id, 'name' => (string) $e->actor_name])
            ->unique('id')->values()->all();

        return Inertia::render('audit/index', [
            'filters' => $filters,
            'events' => $events,
            'areas' => AuditAction::areas(),
            'actors' => $actors,
            'selected' => $this->selected($request, $user),
        ]);
    }

    /**
     * @return array{area: string|null, actor: int|null, from: string|null, to: string|null, q: string}
     */
    private function filters(Request $request): array
    {
        $area = $request->query('area');

        return [
            'area' => is_string($area) && in_array($area, AuditAction::areas(), true) ? $area : null,
            'actor' => $request->integer('actor') ?: null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array{area: string|null, actor: int|null, from: string|null, to: string|null, q: string}  $filters
     * @return Builder<AuditEvent>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $zone = Facility::current()->timezone ?? config('app.timezone');

        return $query
            ->when($filters['area'], fn (Builder $q, string $area) => $q->where('area', $area))
            ->when($filters['actor'], fn (Builder $q, int $actor) => $q->where('actor_user_id', $actor))
            ->when($filters['from'], fn (Builder $q, string $from) => $q->where('occurred_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $q, string $to) => $q->where('occurred_at', '<=', CarbonImmutable::parse($to, $zone)->endOfDay()->utc()))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $q->where(fn (Builder $w) => $w->where('subject_label', 'like', $term)->orWhere('actor_name', 'like', $term));
            });
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action,
            'area' => $event->area,
            'actor' => $event->actor_name,
            'source' => $event->source,
            'subject' => $event->subject_label,
            'subject_type' => $event->subject_type === null ? null : class_basename($event->subject_type),
            'has_reason' => $event->reason !== null,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selected(Request $request, User $user): ?array
    {
        $id = $request->integer('event');
        if ($id <= 0) {
            return null;
        }

        $event = AuditEvent::query()->find($id);
        if ($event === null || ! $this->access->can($user, Permission::P63, $event)) {
            return null;
        }

        $branchIds = $event->accessBranchIds();

        return [
            ...$this->row($event),
            'subject_id' => $event->subject_id,
            'before' => $event->before === null ? null : AuditRecorder::redact($event->before),
            'after' => $event->after === null ? null : AuditRecorder::redact($event->after),
            'reason' => $event->reason,
            'request_id' => $event->request_id,
            'user_agent' => $event->user_agent,
            'ip_ref' => $event->ip_hash === null ? null : substr($event->ip_hash, 0, 12),
            'branches' => $branchIds === [] ? [] : Branch::query()->whereIn('id', $branchIds)->orderBy('id')->get()
                ->map(fn (Branch $b) => $b->name_ar.($b->name_en ? ' / '.$b->name_en : ''))->all(),
        ];
    }
}
