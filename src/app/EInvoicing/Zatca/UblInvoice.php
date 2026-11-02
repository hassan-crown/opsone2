<?php

namespace App\EInvoicing\Zatca;

use App\Models\EInvoicing\EInvoice;
use DOMDocument;
use DOMElement;

/**
 * UBL 2.1 XML of an e-invoice per the ZATCA XML implementation standard (ADR-0033): `Invoice` root for invoices,
 * credit notes (381) and debit notes (383), ICV and PIH references, the QR reference (simplified only, filled after
 * signing) and the `cac:Signature` marker, seller and buyer parties, supply date, payment means (with the reason of a
 * note), the two TaxTotals (document VAT, then the standard-rated breakdown), monetary totals and lines.
 *
 * Amounts are the stored halalas written with two decimals; nothing is recomputed here, so the XML always carries the
 * exact numbers of the PDF and the ledger. The output is compact (no indentation): removing the signature parts for
 * hashing leaves no stray whitespace behind.
 */
final class UblInvoice
{
    public const NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    /** Replaced by the phase 2 QR once the invoice is signed. */
    public const QR_PLACEHOLDER = '__ZATCA_QR__';

    private DOMDocument $doc;

    private function __construct(private readonly EInvoice $invoice, private readonly ?string $originalNumber)
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
    }

    public static function build(EInvoice $invoice, ?string $originalNumber = null): string
    {
        return (new self($invoice, $originalNumber))->render();
    }

    /**
     * Minor units → `1234.50`.
     */
    public static function amount(int $minor): string
    {
        $abs = abs($minor);

        return ($minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function percent(int $bp): string
    {
        return intdiv($bp, 100).'.'.str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT);
    }

    private function render(): string
    {
        $i = $this->invoice;
        $root = $this->doc->createElementNS(self::NS, 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', self::EXT);
        $this->doc->appendChild($root);

        $this->cbc($root, 'ProfileID', 'reporting:1.0');
        $this->cbc($root, 'ID', $i->number);
        $this->cbc($root, 'UUID', $i->uuid);
        $this->cbc($root, 'IssueDate', $i->issue_date->format('Y-m-d'));
        $this->cbc($root, 'IssueTime', $i->issue_time);
        $this->cbc($root, 'InvoiceTypeCode', $i->typeCode(), ['name' => $i->subtypeCode()]);
        $this->cbc($root, 'DocumentCurrencyCode', $i->currency);
        $this->cbc($root, 'TaxCurrencyCode', $i->currency);

        if ($i->isNote() && $this->originalNumber !== null) {
            $billing = $this->cac($root, 'BillingReference');
            $this->cbc($this->cac($billing, 'InvoiceDocumentReference'), 'ID', $this->originalNumber);
        }

        $icv = $this->cac($root, 'AdditionalDocumentReference');
        $this->cbc($icv, 'ID', 'ICV');
        $this->cbc($icv, 'UUID', (string) $i->icv);

        $pih = $this->cac($root, 'AdditionalDocumentReference');
        $this->cbc($pih, 'ID', 'PIH');
        $this->cbc($this->cac($pih, 'Attachment'), 'EmbeddedDocumentBinaryObject', $i->previous_hash, ['mimeCode' => 'text/plain']);

        if ($i->type === EInvoice::SIMPLIFIED) {
            $qr = $this->cac($root, 'AdditionalDocumentReference');
            $this->cbc($qr, 'ID', 'QR');
            $this->cbc($this->cac($qr, 'Attachment'), 'EmbeddedDocumentBinaryObject', self::QR_PLACEHOLDER, ['mimeCode' => 'text/plain']);
        }

        $signature = $this->cac($root, 'Signature');
        $this->cbc($signature, 'ID', 'urn:oasis:names:specification:ubl:signature:Invoice');
        $this->cbc($signature, 'SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');

        $this->party($this->cac($root, 'AccountingSupplierParty'), $i->seller, true);
        $this->party($this->cac($root, 'AccountingCustomerParty'), $i->buyer, false);

        $this->cbc($this->cac($root, 'Delivery'), 'ActualDeliveryDate', $i->issue_date->format('Y-m-d'));

        $means = $this->cac($root, 'PaymentMeans');
        $this->cbc($means, 'PaymentMeansCode', $i->payment_means_code);
        if ($i->isNote()) {
            $this->cbc($means, 'InstructionNote', mb_substr((string) $i->reason, 0, 1000));
        }

        $this->cbc($this->cac($root, 'TaxTotal'), 'TaxAmount', self::amount($i->vat_minor), ['currencyID' => $i->currency]);
        $tax = $this->cac($root, 'TaxTotal');
        $this->cbc($tax, 'TaxAmount', self::amount($i->vat_minor), ['currencyID' => $i->currency]);
        $subtotal = $this->cac($tax, 'TaxSubtotal');
        $this->cbc($subtotal, 'TaxableAmount', self::amount($i->net_minor), ['currencyID' => $i->currency]);
        $this->cbc($subtotal, 'TaxAmount', self::amount($i->vat_minor), ['currencyID' => $i->currency]);
        $this->taxCategory($this->cac($subtotal, 'TaxCategory'));

        $totals = $this->cac($root, 'LegalMonetaryTotal');
        $this->cbc($totals, 'LineExtensionAmount', self::amount($i->net_minor), ['currencyID' => $i->currency]);
        $this->cbc($totals, 'TaxExclusiveAmount', self::amount($i->net_minor), ['currencyID' => $i->currency]);
        $this->cbc($totals, 'TaxInclusiveAmount', self::amount($i->total_minor), ['currencyID' => $i->currency]);
        $this->cbc($totals, 'AllowanceTotalAmount', '0.00', ['currencyID' => $i->currency]);
        $this->cbc($totals, 'PrepaidAmount', '0.00', ['currencyID' => $i->currency]);
        $this->cbc($totals, 'PayableAmount', self::amount($i->total_minor), ['currencyID' => $i->currency]);

        foreach ($i->lines as $n => $line) {
            $this->line($root, $n + 1, $line);
        }

        return (string) $this->doc->saveXML();
    }

    /**
     * @param  array<string, mixed>  $party
     */
    private function party(DOMElement $parent, array $party, bool $seller): void
    {
        $node = $this->cac($parent, 'Party');
        $id = $party['commercial_registration'] ?? null;
        if (is_string($id) && $id !== '') {
            $this->cbc($this->cac($node, 'PartyIdentification'), 'ID', $id, ['schemeID' => 'CRN']);
        }

        if (($party['street'] ?? '') !== '' || $seller) {
            $address = $this->cac($node, 'PostalAddress');
            $this->cbc($address, 'StreetName', (string) ($party['street'] ?? ''));
            if (($party['additional_number'] ?? '') !== '' && $party['additional_number'] !== null) {
                $this->cbc($address, 'AdditionalStreetName', (string) $party['additional_number']);
            }
            $this->cbc($address, 'BuildingNumber', (string) ($party['building_number'] ?? ''));
            $this->cbc($address, 'CitySubdivisionName', (string) ($party['district'] ?? ''));
            $this->cbc($address, 'CityName', (string) ($party['city'] ?? ''));
            $this->cbc($address, 'PostalZone', (string) ($party['postal_code'] ?? ''));
            $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', (string) ($party['country'] ?? 'SA'));
        }

        $vat = $party['vat_number'] ?? null;
        if (is_string($vat) && $vat !== '') {
            $scheme = $this->cac($node, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $vat);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }

        $name = $party['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $this->cbc($this->cac($node, 'PartyLegalEntity'), 'RegistrationName', $name);
        }
    }

    /**
     * @param  array{description: string, quantity: string, unit: string|null, net_minor: int, vat_minor: int, gross_minor: int}  $line
     */
    private function line(DOMElement $root, int $number, array $line): void
    {
        $currency = $this->invoice->currency;
        $node = $this->cac($root, 'InvoiceLine');
        $this->cbc($node, 'ID', (string) $number);
        $quantity = self::quantity($line['quantity']);
        $this->cbc($node, 'InvoicedQuantity', $quantity, ['unitCode' => 'PCE']);
        $this->cbc($node, 'LineExtensionAmount', self::amount($line['net_minor']), ['currencyID' => $currency]);

        $tax = $this->cac($node, 'TaxTotal');
        $this->cbc($tax, 'TaxAmount', self::amount($line['vat_minor']), ['currencyID' => $currency]);
        $this->cbc($tax, 'RoundingAmount', self::amount($line['gross_minor']), ['currencyID' => $currency]);

        $item = $this->cac($node, 'Item');
        $this->cbc($item, 'Name', mb_substr($line['description'] !== '' ? $line['description'] : '-', 0, 1000));
        $category = $this->cac($item, 'ClassifiedTaxCategory');
        $this->cbc($category, 'ID', 'S');
        $this->cbc($category, 'Percent', self::percent($this->invoice->vat_rate_bp));
        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');

        // Unit price before VAT. When the net does not divide evenly by the quantity, the price is given for the whole
        // quantity (BaseQuantity), so quantity × price ÷ base quantity is the line net exactly.
        $price = $this->cac($node, 'Price');
        $units = self::unitPrice($line['net_minor'], $quantity);
        if ($units !== null) {
            $this->cbc($price, 'PriceAmount', $units, ['currencyID' => $currency]);
        } else {
            $this->cbc($price, 'PriceAmount', self::amount($line['net_minor']), ['currencyID' => $currency]);
            $this->cbc($price, 'BaseQuantity', $quantity, ['unitCode' => 'PCE']);
        }
    }

    private function taxCategory(DOMElement $category): void
    {
        $this->cbc($category, 'ID', 'S', ['schemeID' => 'UN/ECE 5305', 'schemeAgencyID' => '6']);
        $this->cbc($category, 'Percent', self::percent($this->invoice->vat_rate_bp));
        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT', ['schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']);
    }

    /**
     * @return numeric-string
     */
    private static function quantity(string $quantity): string
    {
        $quantity = trim($quantity);
        if (preg_match('/^\d+(\.\d+)?$/', $quantity) !== 1 || ! is_numeric($quantity) || bccomp($quantity, '0', 6) <= 0) {
            return '1';
        }

        /** @var numeric-string $normalized */
        $normalized = str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;

        return $normalized;
    }

    /**
     * The net per unit with two decimals when it is exact (a whole number of halalas), else null.
     */
    /**
     * @param  numeric-string  $quantity
     */
    private static function unitPrice(int $netMinor, string $quantity): ?string
    {
        $unit = bcdiv((string) $netMinor, $quantity, 0);
        if (bccomp(bcmul($unit, $quantity, 6), (string) $netMinor, 6) !== 0) {
            return null;
        }

        return self::amount((int) $unit);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function cbc(DOMElement $parent, string $name, string $value, array $attributes = []): DOMElement
    {
        $node = $this->doc->createElementNS(self::CBC, 'cbc:'.$name);
        $node->appendChild($this->doc->createTextNode($value));
        foreach ($attributes as $key => $attribute) {
            $node->setAttribute($key, $attribute);
        }
        $parent->appendChild($node);

        return $node;
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $node = $this->doc->createElementNS(self::CAC, 'cac:'.$name);
        $parent->appendChild($node);

        return $node;
    }
}
