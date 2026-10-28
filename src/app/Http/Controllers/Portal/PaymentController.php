<?php

namespace App\Http\Controllers\Portal;

use App\Files\Rules\AllowedAttachment;
use App\Finance\CustomerProofs;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PayOrder / PayPending (P-68, FIN-05 customer side): the amount due and «paid by transfer? attach the receipt». A proof
 * is under review until the facility confirms it (it is never counted as paid before); a correction request lets the
 * customer upload a replacement, and an unconfirmed proof can be withdrawn. Online payment arrives with the store wave.
 */
final class PaymentController extends PortalController
{
    public function __construct(private readonly CustomerProofs $proofs) {}

    public function show(string $order): Response
    {
        $customer = $this->customer();
        $record = PortalAccess::order($customer, $order);

        return Inertia::render('portal/pay', [
            'order' => PortalPresenter::order($record),
            'finance' => PortalPresenter::finance($record),
            'accepts_proofs' => PortalPresenter::acceptsProofs($record),
            'replaces' => request()->query('replaces'),
        ]);
    }

    public function store(Request $request, string $order): RedirectResponse
    {
        $record = PortalAccess::order($this->customer(), $order);
        abort_unless(PortalPresenter::acceptsProofs($record), 404);
        [$data, $file] = self::input($request);
        $this->proofs->submit($this->actor(), $record, $data, $file);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.payments.submitted')]);

        return redirect()->route('customer.orders.show', $record->id);
    }

    public function resubmit(Request $request, string $proof): RedirectResponse
    {
        $previous = PortalAccess::proof($this->customer(), $proof);
        [$data, $file] = self::input($request);
        $this->proofs->resubmit($this->actor(), $previous, $data, $file);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.payments.submitted')]);

        return redirect()->route('customer.orders.show', $previous->order_id);
    }

    public function withdraw(string $proof): RedirectResponse
    {
        $record = PortalAccess::proof($this->customer(), $proof);
        $this->proofs->withdraw($this->actor(), $record);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('portal.payments.withdrawn')]);

        return redirect()->route('customer.orders.show', $record->order_id);
    }

    /**
     * @return array{0: array<string, mixed>, 1: UploadedFile}
     */
    private static function input(Request $request): array
    {
        $data = $request->validate([
            ...CustomerProofs::rules(),
            'file' => ['required', 'file', new AllowedAttachment],
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return [array_intersect_key($data, array_flip(['amount', 'method', 'paid_on', 'reference'])), $file];
    }
}
