<?php

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Store\StoreCheckout;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Store/StoreTestHelpers.php';
require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';
require_once __DIR__.'/../Site/SiteTestHelpers.php';

/*
| HTTP drivers of the binding scenarios 2, 3, 5, 15, 16, 17, 18 («اختبارات ترابط ملزمة»). Every helper is prefixed
| `accP`. Fixtures come from the domain helpers; scenario steps go through the real endpoints as the acting party:
| staff on the web guard, customers on the customer guard (store checkout or `/portal`), the payment provider through
| the Moyasar webhook (faked as in tests/Feature/Store — test keys only, never a real network call).
*/

function accPKey(string $prefix = 'accp'): string
{
    return $prefix.'-'.Str::uuid();
}

function accPPdf(string $name = 'transfer.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".Str::random(16));
}

/**
 * A fresh browser for a party: a new session (no cart, no stamp of someone else) and no signed-in guard.
 */
function accPFreshBrowser(): void
{
    Auth::forgetGuards();
    test()->flushSession();
}

/**
 * A staff member in their own browser (fresh session, web guard).
 */
function accPStaff(User $user): void
{
    accPFreshBrowser();
    test()->actingAs($user);
}

/**
 * A customer signed in on the customer guard in their own browser (fresh session with the portal stamp).
 */
function accPCustomer(Customer $customer): void
{
    accPFreshBrowser();
    actingAsCustomer($customer);
}

/**
 * The buyer's store checkout over HTTP: the variant in the session cart, then the checkout start — a redirect to the
 * provider's invoice page, or back with errors.
 */
function accPStoreCheckout(Customer $customer, int $variantId, int $quantity, ?string $key = null): TestResponse
{
    accPCustomer($customer);
    test()->post('/ar/store/cart', ['variant_id' => $variantId, 'quantity' => $quantity])->assertRedirect('/ar/store/cart');

    return test()->from('/ar/store/checkout')->post('/ar/store/checkout', ['idempotency_key' => $key ?? accPKey('ck')]);
}

function accPCheckoutOf(Customer $customer): StoreCheckout
{
    return StoreCheckout::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
}

/**
 * Audit actions recorded, in order, optionally for one subject.
 *
 * @return list<string>
 */
function accPAudit(string $prefix, ?string $subjectType = null, ?int $subjectId = null): array
{
    return AuditEvent::query()->where('action', 'like', $prefix.'%')
        ->when($subjectType !== null, fn ($q) => $q->where('subject_type', $subjectType)->where('subject_id', $subjectId))
        ->orderBy('id')->pluck('action')->all();
}
