<?php

namespace App\Http\Controllers\Rules;

use App\Actions\Rules\PublishConfigRelease;
use App\Actions\Rules\RulesAccess;
use App\Configuration\ConfigCheck;
use App\Configuration\ConfigDiff;
use App\Configuration\ConfigPreview;
use App\Configuration\ConfigReleases;
use App\Configuration\ConfigSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rules\ConfigPreviewRequest;
use App\Http\Requests\Rules\ConfigReleaseRequest;
use App\Models\ConfigRelease;
use App\Models\Order;
use App\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › releases (ADR-0040): the pending draft (its change log against the current release and the publish
 * check), «try it» on sample data, publish with notes, the release history with each release's change log, rollback
 * (publishing a copy of an older release) and discarding the draft. Reading needs P-104 or P-02; writes need P-104.
 */
class ConfigReleaseController extends Controller
{
    /** Releases listed on the page (the newest first). */
    private const LISTED = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $current = ConfigReleases::latest();
        $published = ConfigReleases::current();
        $draft = ConfigSnapshot::capture();
        $hash = ConfigSnapshot::hash($draft);

        $releases = ConfigRelease::query()->with(['publisher:id,name', 'restoredFrom:id,number'])->orderByDesc('number')->limit(self::LISTED + 1)->get();
        $rows = [];
        foreach ($releases->take(self::LISTED) as $i => $release) {
            $previous = $releases[$i + 1] ?? null;
            $rows[] = [
                ...$this->present($release, $current),
                'summary' => ConfigDiff::summary(ConfigDiff::between($previous->snapshot ?? ConfigSnapshot::defaults(), $release->snapshot)),
            ];
        }

        return Inertia::render('rules/releases', [
            'current' => $current === null ? null : $this->present($current, $current),
            'draft' => [
                'hash' => $hash,
                'base' => $current->number ?? 0,
                'changes' => ConfigDiff::between($published, $draft),
                'issues' => ConfigCheck::errors($draft, $published),
                'pending' => $current === null || $current->snapshot_hash !== $hash,
            ],
            'releases' => $rows,
            'pinnedOrders' => $current === null ? 0 : Order::query()->where('config_release_id', '!=', $current->id)->count(),
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function show(Request $request, ConfigRelease $release): Response
    {
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $current = ConfigReleases::latest();
        $previous = ConfigRelease::query()->where('number', '<', $release->number)->orderByDesc('number')->first();
        $release->loadMissing(['publisher:id,name', 'restoredFrom:id,number']);

        return Inertia::render('rules/release', [
            'release' => $this->present($release, $current),
            'previous' => $previous?->number,
            'changes' => ConfigDiff::between($previous->snapshot ?? ConfigSnapshot::defaults(), $release->snapshot),
            // What a rollback to this release would change compared with the rules in force.
            'rollback' => $current === null || $current->id === $release->id ? null : ConfigDiff::between($current->snapshot, $release->snapshot),
            'orders' => Order::query()->where('config_release_id', $release->id)->count(),
            'base' => $current->number ?? 0,
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function publish(ConfigReleaseRequest $request, PublishConfigRelease $publish): RedirectResponse
    {
        $release = $publish->publish($request->user(), $request->validated('notes'), $request->integer('base'), (string) $request->validated('draft_hash'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.releases.published', ['number' => $release->number])]);

        return to_route('rules.releases');
    }

    public function rollback(ConfigReleaseRequest $request, ConfigRelease $release, PublishConfigRelease $publish): RedirectResponse
    {
        $new = $publish->rollback($request->user(), $release, $request->validated('notes'), $request->integer('base'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.releases.rolled_back', ['number' => $new->number, 'from' => $release->number])]);

        return to_route('rules.releases');
    }

    public function discard(ConfigReleaseRequest $request, PublishConfigRelease $publish): RedirectResponse
    {
        $publish->discard($request->user(), $request->integer('base'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.releases.discarded')]);

        return to_route('rules.releases');
    }

    /**
     * The draft against the release in force, on sample data. Writes nothing.
     */
    public function preview(ConfigPreviewRequest $request): JsonResponse
    {
        RulesAccess::authorizeView($request->user());
        $value = Money::fromInput(str_replace(',', '.', (string) $request->validated('value')));

        return response()->json(ConfigPreview::run(ConfigSnapshot::capture(), ConfigReleases::current(), $value, (string) $request->validated('locale')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ConfigRelease $release, ?ConfigRelease $current): array
    {
        return [
            'id' => $release->id,
            'number' => $release->number,
            'published_at' => $release->published_at->toIso8601String(),
            'published_by' => $release->publisher?->name,
            'notes' => $release->notes,
            'restored_from' => $release->restoredFrom?->number,
            'is_current' => $release->id === $current?->id,
        ];
    }
}
