<?php

namespace App\Http\Controllers\FieldSales;

use App\Actions\FieldSales\ChangeSalesChannel;
use App\Enums\SalesChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\SalesChannelRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Correcting an order's sales channel and field seller (P-106, ADR-0041) from the order page.
 */
class OrderSalesChannelController extends Controller
{
    public function __invoke(SalesChannelRequest $request, Order $order, ChangeSalesChannel $change): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $change->handle($user, $order, $request->integer('lock_version'), SalesChannel::from((string) $request->input('sales_channel')),
            $request->filled('seller_id') ? $request->integer('seller_id') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.channel_changed')]);

        return back();
    }
}
