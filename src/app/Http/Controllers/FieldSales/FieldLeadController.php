<?php

namespace App\Http\Controllers\FieldSales;

use App\Actions\StateMachines\M03Interest\T01TakeFollowUp;
use App\Actions\StateMachines\M03Interest\T02ConvertInterest;
use App\Actions\StateMachines\M03Interest\T03CloseInterest;
use App\Customers\CustomerAccess;
use App\Enums\InterestCloseReason;
use App\FieldSales\FieldSalesPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\LeadCloseRequest;
use App\Models\Interest;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A lead in the field app (ADR-0041): the rep opens a lead that follows them, takes it, closes it with a reason or
 * converts it into an order (M-03 actions, which authorize again: P-09 / P-15 `assigned`). Someone else's lead is 404.
 */
class FieldLeadController extends Controller
{
    public function show(Request $request, Interest $interest): Response
    {
        $user = $this->user($request);
        $this->ensureVisible($user, $interest);
        $order = $interest->converted_order_id === null ? null : Order::query()->find($interest->converted_order_id);

        return Inertia::render('field/lead', [
            'lead' => FieldSalesPresenter::lead($interest, $user),
            'order' => $order !== null && OrderAccess::canView($user, $order) ? ['id' => $order->id, 'number' => $order->number] : null,
            'closeReasons' => InterestCloseReason::values(),
        ]);
    }

    public function follow(Request $request, Interest $interest, T01TakeFollowUp $follow): RedirectResponse
    {
        $this->ensureVisible($this->user($request), $interest);
        $follow->handle($this->user($request), $interest);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.followed')]);

        return back();
    }

    public function close(LeadCloseRequest $request, Interest $interest, T03CloseInterest $close): RedirectResponse
    {
        $this->ensureVisible($this->user($request), $interest);
        $close->handle($this->user($request), $interest, InterestCloseReason::from((string) $request->input('reason')), $request->input('note'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.closed')]);

        return to_route('field.index');
    }

    public function convert(Request $request, Interest $interest, T02ConvertInterest $convert): RedirectResponse
    {
        $this->ensureVisible($this->user($request), $interest);
        $converted = $convert->handle($this->user($request), $interest);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.converted')]);

        return to_route('field.leads.show', $converted);
    }

    private function ensureVisible(User $user, Interest $interest): void
    {
        abort_unless($interest->assignee_id === $user->id && CustomerAccess::canViewInterest($user, $interest), 404);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
