<?php

use App\EInvoicing\InvoiceContent;
use App\EInvoicing\Zatca\Api\FakeZatcaClient;
use App\EInvoicing\Zatca\Api\ZatcaClients;
use App\EInvoicing\Zatca\Api\ZatcaCredentials;
use App\EInvoicing\Zatca\Api\ZatcaUnavailable;
use App\EInvoicing\Zatca\Certificate;
use App\EInvoicing\Zatca\CsrBuilder;
use App\EInvoicing\Zatca\InvoiceHasher;
use App\EInvoicing\Zatca\InvoiceQr;
use App\EInvoicing\Zatca\InvoiceSigner;
use App\EInvoicing\Zatca\Tlv;
use App\EInvoicing\Zatca\UblInvoice;
use App\Models\EInvoicing\EInvoice;
use App\Money\Money;
use App\Money\VatCalculator;
use Illuminate\Support\Facades\Http;

/*
| ZATCA building blocks (ADR-0032), no database: TLV QR, UBL 2.1 XML against the bundled OASIS XSD, the invoice hash,
| ECDSA secp256k1 + XAdES signing and its verification, the CSR.
*/

function zatcaSampleInvoice(array $overrides = []): EInvoice
{
    $content = InvoiceContent::amountLine('خدمة تفصيل مطبخ', 11_500, 1500);

    return new EInvoice($overrides + [
        'uuid' => '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
        'number' => 'INV-2026-000001',
        'kind' => EInvoice::KIND_INVOICE,
        'type' => EInvoice::SIMPLIFIED,
        'issue_date' => '2026-11-01',
        'issue_time' => '10:15:00',
        'currency' => 'SAR',
        'seller' => [
            'name' => 'مؤسسة المطابخ الحديثة', 'name_en' => 'Modern Kitchens', 'vat_number' => '399999999900003',
            'commercial_registration' => '1010010000', 'street' => 'طريق الملك فهد', 'building_number' => '1234',
            'additional_number' => '5678', 'district' => 'العليا', 'city' => 'الرياض', 'postal_code' => '12211', 'country' => 'SA',
        ],
        'buyer' => ['name' => 'عميل', 'vat_number' => null, 'street' => '', 'building_number' => '', 'district' => '', 'city' => '', 'postal_code' => '', 'country' => 'SA'],
        'lines' => $content['lines'],
        'vat_rate_bp' => 1500,
        'net_minor' => $content['net_minor'],
        'vat_minor' => $content['vat_minor'],
        'total_minor' => $content['total_minor'],
        'payment_means_code' => '10',
        'icv' => 1,
        'previous_hash' => InvoiceHasher::FIRST_PIH,
    ]);
}

/**
 * A key + certificate issued by the fake ZATCA CA from a real CSR.
 *
 * @return array{key: string, certificate: Certificate}
 */
function zatcaTestCredentials(): array
{
    $keys = CsrBuilder::generate([
        'common_name' => 'EGS-3999999999-test', 'serial' => '1-OpsOne|2-1.0|3-test', 'organization' => 'Modern Kitchens',
        'unit' => 'Riyadh', 'vat_number' => '399999999900003', 'address' => '1234 King Fahd Rd, Riyadh', 'industry' => 'Kitchens',
    ], 'fake');
    $response = (new FakeZatcaClient)->complianceCsid(CsrBuilder::encoded($keys['csr']), '123456');

    return ['key' => $keys['private_key'], 'certificate' => Certificate::from((string) $response->string('binarySecurityToken'))];
}

function zatcaSchemaErrors(string $xml): array
{
    $doc = new DOMDocument;
    $doc->loadXML($xml);
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc->schemaValidate(resource_path('zatca/xsd/maindoc/UBL-Invoice-2.1.xsd'));
    $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    return $errors;
}

describe('TLV QR', function () {
    it('encodes and decodes the phase 1 tags with Arabic text', function () {
        $invoice = zatcaSampleInvoice();
        $qr = InvoiceQr::phaseOne(InvoiceQr::fields($invoice));
        $tags = Tlv::decode($qr);

        expect($tags)->toBe([
            1 => 'مؤسسة المطابخ الحديثة',
            2 => '399999999900003',
            3 => '2026-11-01T10:15:00',
            4 => '115.00',
            5 => '15.00',
        ])->and(ord(base64_decode($qr)[1]))->toBe(strlen('مؤسسة المطابخ الحديثة'));
    });

    it('refuses values longer than one length byte and truncated payloads', function () {
        expect(fn () => Tlv::encode([1 => str_repeat('a', 256)]))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Tlv::decode(base64_encode("\x01\x05ab")))->toThrow(InvalidArgumentException::class);
    });
});

describe('VAT math (inclusive 15%)', function () {
    it('splits an inclusive amount half-up and keeps net + vat = gross', function (int $gross, int $net, int $vat) {
        $line = InvoiceContent::amountLine('x', $gross, 1500);

        expect($line['net_minor'])->toBe($net)->and($line['vat_minor'])->toBe($vat)->and($line['total_minor'])->toBe($gross);
    })->with([[11_500, 10_000, 1_500], [100, 87, 13], [1, 1, 0], [8, 7, 1], [99_999, 86_956, 13_043]]);

    it('sums rounded lines, never a rounding of the total', function () {
        $vat = new VatCalculator(1500, true);
        $lines = array_map(fn (int $g) => $vat->line(Money::of($g)), [100, 100, 100]);
        // each line: vat = round(100 × 15/115) = 13; the total of lines is 39, while rounding 300 would give 39.13 → 39
        expect(array_sum(array_map(fn ($l) => $l->vat->minor, $lines)))->toBe(39)
            ->and(array_sum(array_map(fn ($l) => $l->gross->minor, $lines)))->toBe(300);
    });
});

describe('UBL 2.1 XML', function () {
    it('validates against the bundled OASIS UBL 2.1 Invoice schema (unsigned and signed, all kinds)', function (string $type, string $kind) {
        $credentials = zatcaTestCredentials();
        $invoice = zatcaSampleInvoice(['type' => $type, 'kind' => $kind, 'reason' => $kind === EInvoice::KIND_INVOICE ? null : 'Return of goods',
            'buyer' => $type === EInvoice::STANDARD
                ? ['name' => 'شركة العميل', 'vat_number' => '300000000000003', 'commercial_registration' => '1010000000', 'street' => 'Olaya St', 'building_number' => '1111', 'district' => 'Olaya', 'city' => 'Riyadh', 'postal_code' => '12212', 'country' => 'SA']
                : ['name' => 'عميل', 'vat_number' => null, 'street' => '', 'building_number' => '', 'district' => '', 'city' => '', 'postal_code' => '', 'country' => 'SA']]);
        $xml = UblInvoice::build($invoice, $kind === EInvoice::KIND_INVOICE ? null : 'INV-2026-000001');
        $signed = InvoiceSigner::sign($xml, $credentials['key'], $credentials['certificate'], '2026-11-01T10:15:00', InvoiceQr::fields($invoice), $type === EInvoice::SIMPLIFIED);

        expect(zatcaSchemaErrors(str_replace(UblInvoice::QR_PLACEHOLDER, 'AA==', $xml)))->toBe([])
            ->and(zatcaSchemaErrors($signed['xml']))->toBe([]);
    })->with([
        [EInvoice::STANDARD, EInvoice::KIND_INVOICE], [EInvoice::STANDARD, EInvoice::KIND_CREDIT], [EInvoice::STANDARD, EInvoice::KIND_DEBIT],
        [EInvoice::SIMPLIFIED, EInvoice::KIND_INVOICE], [EInvoice::SIMPLIFIED, EInvoice::KIND_CREDIT], [EInvoice::SIMPLIFIED, EInvoice::KIND_DEBIT],
    ]);

    it('writes the ZATCA structure: type codes, ICV, PIH, billing reference, reason, totals and lines', function () {
        $invoice = zatcaSampleInvoice(['kind' => EInvoice::KIND_CREDIT, 'reason' => 'استرداد', 'icv' => 7, 'previous_hash' => 'abc=']);
        $xpath = InvoiceHasher::xpath(InvoiceHasher::load(UblInvoice::build($invoice, 'INV-2026-000001')));
        $value = fn (string $q) => $xpath->query($q)?->item(0)?->textContent;

        expect($value('/inv:Invoice/cbc:InvoiceTypeCode'))->toBe('381')
            ->and($xpath->query('/inv:Invoice/cbc:InvoiceTypeCode/@name')?->item(0)?->nodeValue)->toBe('0200000')
            ->and($value("//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID"))->toBe('7')
            ->and($value("//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject"))->toBe('abc=')
            ->and($value('//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'))->toBe('INV-2026-000001')
            ->and($value('//cac:PaymentMeans/cbc:InstructionNote'))->toBe('استرداد')
            ->and($value('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'))->toBe('100.00')
            ->and($value('//cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('115.00')
            ->and($value('/inv:Invoice/cac:TaxTotal[2]/cac:TaxSubtotal/cbc:TaxAmount'))->toBe('15.00')
            ->and($value('//cac:InvoiceLine/cac:TaxTotal/cbc:RoundingAmount'))->toBe('115.00');
    });

    it('gives the price for the whole quantity when the net does not divide evenly', function () {
        $invoice = zatcaSampleInvoice(['lines' => [['description' => 'x', 'quantity' => '3', 'unit' => null, 'net_minor' => 10_000, 'vat_minor' => 1_500, 'gross_minor' => 11_500]]]);
        $xpath = InvoiceHasher::xpath(InvoiceHasher::load(UblInvoice::build($invoice)));

        expect($xpath->query('//cac:Price/cbc:PriceAmount')?->item(0)?->textContent)->toBe('100.00')
            ->and($xpath->query('//cac:Price/cbc:BaseQuantity')?->item(0)?->textContent)->toBe('3');
    });
});

describe('hash and signature', function () {
    it('hashes without the signature, the QR and the extensions, so signing keeps the hash', function () {
        $credentials = zatcaTestCredentials();
        $invoice = zatcaSampleInvoice();
        $xml = UblInvoice::build($invoice);
        $signed = InvoiceSigner::sign($xml, $credentials['key'], $credentials['certificate'], '2026-11-01T10:15:00', InvoiceQr::fields($invoice), true);

        expect($signed['hash'])->toBe(InvoiceHasher::hash($xml))
            ->and(InvoiceHasher::hash($signed['xml']))->toBe($signed['hash'])
            ->and(base64_encode(hash('sha256', '0')))->toBe(InvoiceHasher::FIRST_PIH);
    });

    it('verifies a signed invoice and detects tampering', function () {
        $credentials = zatcaTestCredentials();
        $invoice = zatcaSampleInvoice();
        $signed = InvoiceSigner::sign(UblInvoice::build($invoice), $credentials['key'], $credentials['certificate'], '2026-11-01T10:15:00', InvoiceQr::fields($invoice), true);

        expect(InvoiceSigner::verify($signed['xml']))->toMatchArray(['valid' => true, 'errors' => []]);

        $tampered = str_replace('<cbc:PayableAmount currencyID="SAR">115.00', '<cbc:PayableAmount currencyID="SAR">15.00', $signed['xml']);
        expect(InvoiceSigner::verify($tampered)['errors'])->toContain('invoice_digest')->toContain('signature');

        $otherTime = str_replace('<xades:SigningTime>2026-11-01T10:15:00', '<xades:SigningTime>2026-11-02T10:15:00', $signed['xml']);
        expect(InvoiceSigner::verify($otherTime)['errors'])->toBe(['properties_digest']);
    });

    it('puts the phase 2 tags in the QR of a simplified invoice', function () {
        $credentials = zatcaTestCredentials();
        $invoice = zatcaSampleInvoice();
        $signed = InvoiceSigner::sign(UblInvoice::build($invoice), $credentials['key'], $credentials['certificate'], '2026-11-01T10:15:00', InvoiceQr::fields($invoice), true);
        $tags = Tlv::decode($signed['qr']);
        $xpath = InvoiceHasher::xpath(InvoiceHasher::load($signed['xml']));

        expect(array_keys($tags))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9])
            ->and($tags[6])->toBe($signed['hash'])
            ->and($tags[7])->toBe($signed['signature'])
            ->and($tags[8])->toBe($credentials['certificate']->publicKeyDer())
            ->and(openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($tags[8]), 64, "\n")."-----END PUBLIC KEY-----\n"))->not->toBeFalse()
            ->and($xpath->query("//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject")?->item(0)?->textContent)->toBe($signed['qr']);
    });
});

describe('CSR', function () {
    it('creates a secp256k1 key and a CSR with the ZATCA template and subject alternative name', function () {
        $keys = CsrBuilder::generate([
            'common_name' => 'EGS-1', 'serial' => '1-OpsOne|2-1.0|3-abc', 'organization' => 'مؤسسة المطابخ',
            'unit' => 'Riyadh', 'vat_number' => '399999999900003', 'address' => 'Riyadh', 'industry' => 'Kitchens',
        ], 'simulation');

        $details = openssl_pkey_get_details(openssl_pkey_get_private($keys['private_key']));
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $keys['csr']));

        expect($details['ec']['curve_name'] ?? null)->toBe('secp256k1')
            ->and(openssl_csr_get_subject($keys['csr'])['CN'] ?? null)->toBe('EGS-1')
            ->and($der)->toContain('PREZATCA-Code-Signing')
            ->and($der)->toContain('399999999900003')
            ->and($der)->toContain('1-OpsOne|2-1.0|3-abc')
            ->and(base64_decode(CsrBuilder::encoded($keys['csr'])))->toBe($keys['csr']);
    });
});

describe('gateway client', function () {
    it('sends ZATCA\'s headers, basic auth and Base64 invoice to the simulation gateway, and retries 5xx', function () {
        config(['zatca.environments' => ['fake', 'simulation']]);
        Http::fake([
            'gw-fatoora.zatca.gov.sa/e-invoicing/simulation/invoices/reporting/single' => Http::sequence()
                ->push(['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []]], 200)
                ->push([], 503),
            'gw-fatoora.zatca.gov.sa/e-invoicing/simulation/compliance' => Http::response(['requestID' => '1234', 'binarySecurityToken' => 'TUlJ', 'secret' => 's3cr3t'], 200),
        ]);
        $client = app(ZatcaClients::class)->for('simulation');
        $credentials = new ZatcaCredentials('token', 'secret');

        $response = $client->report($credentials, 'uuid-1', 'hash=', '<Invoice/>');
        expect($response->ok())->toBeTrue()->and($response->string('reportingStatus'))->toBe('REPORTED')
            ->and($client->complianceCsid('Q1NS', '123456')->string('requestID'))->toBe('1234')
            ->and(fn () => $client->report($credentials, 'uuid-1', 'hash=', '<Invoice/>'))->toThrow(ZatcaUnavailable::class);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/invoices/reporting/single')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('token:secret'))
            && $request->hasHeader('Accept-Version', 'V2')
            && $request['uuid'] === 'uuid-1' && $request['invoiceHash'] === 'hash=' && base64_decode($request['invoice']) === '<Invoice/>');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/compliance') && $request->hasHeader('OTP', '123456') && $request['csr'] === 'Q1NS');
    });

    it('refuses an environment the deployment does not allow', function () {
        config(['zatca.environments' => ['fake']]);

        expect(fn () => app(ZatcaClients::class)->for('production'))->toThrow(InvalidArgumentException::class);
    });

    it('really validates against the schema (a foreign element fails)', function () {
        $xml = str_replace('<cbc:ProfileID>', '<cbc:Bogus>x</cbc:Bogus><cbc:ProfileID>', UblInvoice::build(zatcaSampleInvoice()));

        expect(zatcaSchemaErrors($xml))->not->toBe([]);
    });
});
