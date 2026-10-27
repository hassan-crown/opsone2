<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\ManageContacts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\ContactRequest;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Contact persons of an organization (COM-02, P-10): add, edit, set primary, remove.
 */
class CustomerContactController extends Controller
{
    public function store(ContactRequest $request, Customer $customer, ManageContacts $contacts): RedirectResponse
    {
        $contacts->add($this->actor($request), $customer, $request->validated());
        $this->toast(__('customers.contacts.added'));

        return to_route('customers.show', $customer);
    }

    public function update(ContactRequest $request, CustomerContact $contact, ManageContacts $contacts): RedirectResponse
    {
        $contacts->update($this->actor($request), $contact, $request->validated());
        $this->toast(__('customers.contacts.updated'));

        return to_route('customers.show', $contact->customer_id);
    }

    public function primary(Request $request, CustomerContact $contact, ManageContacts $contacts): RedirectResponse
    {
        $contacts->setPrimary($this->actor($request), $contact);
        $this->toast(__('customers.contacts.primary_set'));

        return to_route('customers.show', $contact->customer_id);
    }

    public function destroy(Request $request, CustomerContact $contact, ManageContacts $contacts): RedirectResponse
    {
        $contacts->remove($this->actor($request), $contact);
        $this->toast(__('customers.contacts.removed'));

        return to_route('customers.show', $contact->customer_id);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
