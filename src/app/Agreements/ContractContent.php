<?php

namespace App\Agreements;

use App\Models\Agreements\Agreement;
use App\Models\Facility;
use App\Models\Order;

/**
 * A contract built from the agreement (AGR-03, Q-36 c): the parties' data and the agreed lines, value, terms and
 * execution period copied in at preparation, in the facility's default language with the fixed v1 clauses. Missing
 * party data is listed for the preparer and never blocks preparing it (SRS domain 04).
 */
final class ContractContent
{
    public const CLAUSES = 4;

    /**
     * @return array<string, mixed>
     */
    public static function fromAgreement(Order $order, Agreement $agreement, ?string $terms): array
    {
        $facility = Facility::current();
        $customer = $order->customer;
        $language = $facility?->default_locale === 'en' ? 'en' : 'ar';
        $content = $agreement->content;

        $nameEn = $facility?->name_en;
        $parties = [
            'facility' => [
                'name' => $language === 'en' && (string) $nameEn !== '' ? $nameEn : $facility?->name_ar,
                'address' => $facility?->address,
                'phone' => $facility?->contact_phone,
                // Not held by the facility profile yet (open data gap): shown as missing to the preparer.
                'commercial_registration' => null,
                'vat_number' => null,
            ],
            'customer' => [
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'commercial_registration' => $customer->commercial_registration,
                'vat_number' => $customer->vat_number,
            ],
        ];
        $missing = [];
        foreach ($parties as $party => $fields) {
            foreach ($fields as $field => $value) {
                if ($value === null || $value === '') {
                    $missing[] = $party.'.'.$field;
                }
            }
        }

        return [
            'source' => 'agreement',
            'agreement_version' => $agreement->version,
            'language' => $language,
            'parties' => $parties,
            'missing' => $missing,
            'lines' => array_map(fn (array $l) => array_intersect_key($l, array_flip(['description', 'quantity', 'unit', 'gross_minor'])), $content['lines'] ?? []),
            'totals' => $content['totals'] ?? [],
            'vat' => $content['vat'] ?? [],
            'execution_days' => $content['execution_days'] ?? null,
            'agreement_terms' => $content['terms'] ?? null,
            'clauses' => array_map(fn (int $i) => __("agreements.contract.clause.{$i}", [], $language), range(1, self::CLAUSES)),
            'terms' => $terms,
        ];
    }
}
