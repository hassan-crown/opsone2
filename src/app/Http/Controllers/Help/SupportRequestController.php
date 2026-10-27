<?php

namespace App\Http\Controllers\Help;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Help\StoreSupportRequestRequest;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\SupportRequest;
use App\Models\User;
use App\Notifications\Outbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SupportRequest (SHR-14, request side): stores the request, mails it to OpsOne support through the outbox and
 * audits it. OpsOne never sees facility data from this form; temporary access is the owner's separate grant (M-38).
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

    public function store(StoreSupportRequestRequest $request, Outbox $outbox): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user, $outbox) {
            $supportRequest = SupportRequest::query()->create([
                ...$request->validated(),
                'page_path' => $this->safePath($request->validated('page_path')),
                'user_id' => $user->id,
                'locale' => app()->getLocale(),
                'status' => SupportRequest::STATUS_PENDING,
            ]);

            // Delivered to OpsOne support through the outbox (Q-22); without a support address it stays pending.
            $to = config('opsone.support.email');
            if (is_string($to) && $to !== '') {
                $outbox->enqueueMail($to, $this->mailTitle($supportRequest), $this->mailLines($supportRequest, $user), null, 'en', "support-request:{$supportRequest->id}:mail");
                $supportRequest->forceFill(['status' => SupportRequest::STATUS_QUEUED])->save();
            }

            Audit::record(AuditAction::SupportRequestSubmitted, $supportRequest, $user, after: [
                'reference' => $supportRequest->reference(),
                'category' => $supportRequest->category,
                'status' => $supportRequest->status,
            ], label: $supportRequest->reference());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('help.support.sent')]);

        return redirect()->route('help.support');
    }

    private function mailTitle(SupportRequest $supportRequest): string
    {
        return "[{$supportRequest->reference()}] {$supportRequest->subject}";
    }

    /**
     * @return list<string>
     */
    private function mailLines(SupportRequest $supportRequest, User $user): array
    {
        $facility = Facility::current();

        return [
            'Facility: '.($facility === null ? '-' : ($facility->name_en ?? $facility->name_ar)),
            'From: '.$user->name.' <'.$user->email.'>',
            'Category: '.$supportRequest->category,
            'Page: '.($supportRequest->page_path ?? '-'),
            'Locale: '.$supportRequest->locale,
            $supportRequest->message,
        ];
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
