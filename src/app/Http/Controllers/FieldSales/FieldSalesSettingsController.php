<?php

namespace App\Http\Controllers\FieldSales;

use App\Actions\FieldSales\UpdateFieldSalesSettings;
use App\Actions\Rules\RulesAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\FieldSalesSettingsRequest;
use App\Models\FieldSales\FieldSalesSettings;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › field sales (ADR-0041): the draft of the `field_sales` release section — on/off, the appointment kinds
 * reps handle and the response time of a dispatched lead. Reading needs P-104 or P-02, saving P-104.
 */
class FieldSalesSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        RulesAccess::authorizeView($user);
        $settings = FieldSalesSettings::working();

        return Inertia::render('rules/field-sales', [
            'settings' => [
                'enabled' => $settings->enabled, 'kinds' => $settings->kinds, 'response_hours' => $settings->response_hours,
                'max_discount_bp' => $settings->max_discount_bp,
                'lock_version' => $settings->exists ? $settings->lock_version : null,
            ],
            'allKinds' => FieldSalesSettings::KINDS,
            'canEdit' => RulesAccess::canEdit($user),
        ]);
    }

    public function update(FieldSalesSettingsRequest $request, UpdateFieldSalesSettings $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $update->handle($user, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('rules.saved')]);

        return to_route('rules.field_sales');
    }
}
