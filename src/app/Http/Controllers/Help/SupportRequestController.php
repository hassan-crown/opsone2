<?php

namespace App\Http\Controllers\Help;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Help\StoreSupportRequestRequest;
use App\Models\Membership;
use App\Models\SupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SupportRequest (SHR-14, request side): stores the request. OpsOne never sees facility data from this form;
 * temporary access is a separate authorization the owner grants (P-79).
 */
class SupportRequestController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorizeStaff($request);

        return Inertia::render('help/support-request', [
            'categories' => SupportRequest::CATEGORIES,
            'pagePath' => $this->safePath($request->query('from')),
            'canGrantAccess' => Access::can($request->user(), Permission::P79),
        ]);
    }

    public function store(StoreSupportRequestRequest $request): RedirectResponse
    {
        SupportRequest::query()->create([
            ...$request->validated(),
            'page_path' => $this->safePath($request->validated('page_path')),
            'user_id' => $request->user()->id,
            'locale' => app()->getLocale(),
            'status' => 'pending',
        ]);

        // TODO(P3 notifications): enqueue delivery of this request to OpsOne support through the notification
        // outbox (email channel, Q-22) and flip `status` to `sent` on success. Nothing is sent from here.

        Inertia::flash('toast', ['type' => 'success', 'message' => __('help.support.sent')]);

        return redirect()->route('help.support');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(
            Access::can($request->user(), Permission::P84)
            && Membership::query()->where('user_id', $request->user()->id)->exists(),
            403,
        );
    }

    /** Only a same-site path is kept; anything else is dropped. */
    private function safePath(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//') ? mb_substr($value, 0, 300) : null;
    }
}
