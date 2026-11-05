<?php

namespace App\Agreements;

use App\Enums\DocumentKind;
use App\Models\Agreements\Agreement;
use App\Models\EInvoicing\ZatcaSettings;
use App\Models\Facility;
use App\Models\Order;

/**
 * A contract built from the agreement (AGR-03, Q-36 c): the parties' data (the facility's registration and VAT number
 * from the seller tax profile) and the agreed lines, value, terms and execution period copied in at preparation, in
 * the facility's default language with the facility's clause library (ADR-0037) or the built-in v1 clauses. Missing
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
        $seller = ZatcaSettings::current();

        $nameEn = $facility?->name_en;
        $parties = [
            'facility' => [
                'name' => $language === 'en' && (string) $nameEn !== '' ? $nameEn : $facility?->name_ar,
                'address' => $facility?->address,
                'phone' => $facility?->contact_phone,
                // From the seller tax profile (ZATCA, ADR-0033): one source for invoices and contracts.
                'commercial_registration' => $seller->commercial_registration,
                'vat_number' => $seller->vat_number,
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

        $library = DocumentClauses::forDocument(DocumentKind::Contract, $order, $content, $language);

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
            // The facility's clause library (ADR-0037), else the built-in v1 clauses.
            'clauses' => $library['clauses'] !== [] ? $library['clauses'] : array_map(fn (int $i) => __("agreements.contract.clause.{$i}", [], $language), range(1, self::CLAUSES)),
            'clause_versions' => $library['clause_versions'],
            'terms' => $terms,
        ];
    }
}
