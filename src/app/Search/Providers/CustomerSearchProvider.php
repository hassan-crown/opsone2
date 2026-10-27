<?php

namespace App\Search\Providers;

use App\Customers\CustomerAccess;
use App\Models\User;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;

/**
 * Customers the user may open (P-09/P-10 within the grant's scope). The scope is applied in the query itself, so
 * neither results nor counts reveal customers outside it (SHR-09).
 */
final class CustomerSearchProvider implements SearchProvider
{
    public function key(): string
    {
        return 'customers';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $query = CustomerAccess::visibleCustomers($user);
        FulltextMatch::apply($query, ['customers.name', 'customers.mobile', 'customers.email'], $term, [['name', 'mobile', 'email']]);

        $results = [];
        foreach ($query->orderBy('customers.id')->limit($limit)->get() as $customer) {
            // Defence in depth: the Gate decides per record exactly as opening the profile would.
            if (! CustomerAccess::canViewCustomer($user, $customer)) {
                continue;
            }
            $results[] = new SearchResult('customer', $customer->name, route('customers.show', $customer, false), $customer->mobile);
        }

        return $results;
    }
}
