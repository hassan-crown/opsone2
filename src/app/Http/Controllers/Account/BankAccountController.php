<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\Organization\DeleteBankAccount;
use App\Actions\Organization\SaveBankAccount;
use App\Finance\BankTransfer\SaudiIban;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SaveBankAccountRequest;
use App\Models\FacilityBankAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The facility's bank accounts for customer transfers (ADR-0046). Viewing needs P-01 (or P-02), changes P-02.
 */
class BankAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P01) || Access::can($user, Permission::P02), 403);

        return Inertia::render('account/bank-accounts', [
            'accounts' => FacilityBankAccount::query()->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('id')->get()
                ->map(fn (FacilityBankAccount $a) => [
                    'id' => $a->id,
                    'bank_name' => $a->bank_name,
                    'beneficiary_name' => $a->beneficiary_name,
                    'iban' => $a->iban,
                    'iban_grouped' => SaudiIban::grouped($a->iban),
                    'account_number' => $a->account_number,
                    'swift_code' => $a->swift_code,
                    'currency' => $a->currency,
                    'transfer_note' => $a->transfer_note,
                    'is_default' => $a->is_default,
                    'is_active' => $a->is_active,
                ])->all(),
            'currencies' => config('account.currencies'),
            'defaultCurrency' => 'SAR',
            'canEdit' => Access::can($user, Permission::P02),
        ]);
    }

    public function store(SaveBankAccountRequest $request, SaveBankAccount $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        return $this->saved();
    }

    public function update(SaveBankAccountRequest $request, FacilityBankAccount $account, SaveBankAccount $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated(), $account);

        return $this->saved();
    }

    public function destroy(Request $request, FacilityBankAccount $account, DeleteBankAccount $action): RedirectResponse
    {
        abort_unless(Access::can($request->user(), Permission::P02), 403);
        $action->handle($request->user(), $account);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('bank.deleted')]);

        return to_route('settings.bank');
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('bank.saved')]);

        return to_route('settings.bank');
    }
}
