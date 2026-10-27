<?php

namespace App\Http\Controllers\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Notifications\NotificationCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My notification preferences (P-64): the email copy of optional types can be turned off. In-app delivery and
 * mandatory (security/account) emails are not optional.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P64), 403);

        $saved = NotificationPreference::query()->where('user_id', $user->id)->pluck('email', 'type');

        return Inertia::render('settings/notifications', [
            'types' => array_map(fn (string $type) => [
                'type' => $type,
                'title' => NotificationCatalogue::get($type)['title'] ?? $type,
                'email' => (bool) ($saved[$type] ?? true),
            ], NotificationCatalogue::optionalTypes()),
            'mandatory' => array_map(
                fn (string $type) => ['type' => $type, 'title' => NotificationCatalogue::get($type)['title'] ?? $type],
                array_keys(array_filter(NotificationCatalogue::all(), fn (array $d) => $d['mandatory'])),
            ),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P64), 403);

        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.type' => ['required', 'string', Rule::in(NotificationCatalogue::optionalTypes())],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $row) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'type' => $row['type']],
                ['email' => (bool) $row['email']],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('notifications.preferences.saved')]);

        return back();
    }
}
