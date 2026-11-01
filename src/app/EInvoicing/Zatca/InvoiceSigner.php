<?php

namespace App\EInvoicing\Zatca;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * Enveloped XAdES signature of a ZATCA invoice (ADR-0032), ECDSA secp256k1 with SHA-256:
 *
 * 1. invoice digest = InvoiceHasher::hash (Base64 of the binary SHA-256 of the canonical invoice);
 * 2. SignatureValue = ECDSA-SHA256 over that binary digest (DER, Base64);
 * 3. SignedProperties: signing time + certificate digest (Base64 of the hex SHA-256 of the certificate body) + issuer
 *    and serial; its digest is the Base64 of the hex SHA-256 of the exact SignedProperties text written in the XML;
 * 4. the UBLExtensions block goes first in the invoice and, for simplified invoices, the phase 2 QR replaces the
 *    placeholder (tags 1–9).
 *
 * Signing is text insertion into the compact XML from UblInvoice, so what is hashed is byte for byte what is stored.
 */
final class InvoiceSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    /**
     * @param  array{seller_name: string, vat_number: string, timestamp: string, total: string, vat: string}  $qrFields
     * @return array{xml: string, hash: string, signature: string, qr: string}
     */
    public static function sign(string $xml, OpenSSLAsymmetricKey|string $privateKey, Certificate $certificate, string $signingTime, array $qrFields, bool $simplified): array
    {
        $hash = InvoiceHasher::hash($xml);
        if (! openssl_sign((string) base64_decode($hash), $raw, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new InvalidArgumentException('The invoice could not be signed with this key.');
        }
        $signature = base64_encode($raw);

        $properties = self::signedProperties($signingTime, $certificate);
        $extension = self::extension($hash, base64_encode(hash('sha256', $properties)), $signature, $certificate->base64, $properties);

        $qr = InvoiceQr::phaseTwo($qrFields, $hash, $signature, $certificate, $simplified);
        $signed = (string) preg_replace('/(<Invoice\b[^>]*>)/', '$1'.str_replace('$', '\$', $extension), $xml, 1);
        $signed = str_replace(UblInvoice::QR_PLACEHOLDER, $qr, $signed);

        return ['xml' => $signed, 'hash' => $hash, 'signature' => $signature, 'qr' => $qr];
    }

    /**
     * Re-checks a signed invoice: the invoice digest, the SignedProperties digest, the certificate digest and the
     * ECDSA signature against the embedded certificate's public key.
     *
     * @return array{valid: bool, errors: list<string>, hash: string|null}
     */
    public static function verify(string $xml): array
    {
        $errors = [];
        try {
            $doc = InvoiceHasher::load($xml);
        } catch (InvalidArgumentException) {
            return ['valid' => false, 'errors' => ['xml'], 'hash' => null];
        }
        $xpath = InvoiceHasher::xpath($doc);
        $text = fn (string $q): ?string => InvoiceHasher::text($xpath, $q);

        $hash = InvoiceHasher::hash($xml);
        if ($text("//ds:Reference[@Id='invoiceSignedData']/ds:DigestValue") !== $hash) {
            $errors[] = 'invoice_digest';
        }

        $certificateText = $text('//ds:X509Certificate');
        $signature = $text('//ds:SignatureValue');
        if ($certificateText === null || $signature === null) {
            return ['valid' => false, 'errors' => [...$errors, 'signature_missing'], 'hash' => $hash];
        }
        $certificate = Certificate::from($certificateText);

        if (preg_match('/<xades:SignedProperties\b.*?<\/xades:SignedProperties>/s', $xml, $m) !== 1
            || $text("//ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue") !== base64_encode(hash('sha256', $m[0]))) {
            $errors[] = 'properties_digest';
        }
        if ($text('//xades:CertDigest/ds:DigestValue') !== $certificate->digest()) {
            $errors[] = 'certificate_digest';
        }
        if (openssl_verify((string) base64_decode($hash), (string) base64_decode($signature), $certificate->publicKey(), OPENSSL_ALGO_SHA256) !== 1) {
            $errors[] = 'signature';
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'hash' => $hash];
    }

    private static function signedProperties(string $signingTime, Certificate $certificate): string
    {
        $issuer = htmlspecialchars($certificate->issuerName(), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<xades:SignedProperties xmlns:xades="'.self::XADES.'" Id="xadesSignedProperties">'
            .'<xades:SignedSignatureProperties>'
            .'<xades:SigningTime>'.$signingTime.'</xades:SigningTime>'
            .'<xades:SigningCertificate><xades:Cert><xades:CertDigest>'
            .'<ds:DigestMethod xmlns:ds="'.self::DS.'" Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            .'<ds:DigestValue xmlns:ds="'.self::DS.'">'.$certificate->digest().'</ds:DigestValue>'
            .'</xades:CertDigest><xades:IssuerSerial>'
            .'<ds:X509IssuerName xmlns:ds="'.self::DS.'">'.$issuer.'</ds:X509IssuerName>'
            .'<ds:X509SerialNumber xmlns:ds="'.self::DS.'">'.$certificate->serialNumber().'</ds:X509SerialNumber>'
            .'</xades:IssuerSerial></xades:Cert></xades:SigningCertificate>'
            .'</xades:SignedSignatureProperties>'
            .'</xades:SignedProperties>';
    }

    private static function extension(string $invoiceDigest, string $propertiesDigest, string $signature, string $certificate, string $properties): string
    {
        $xpath = 'http://www.w3.org/TR/1999/REC-xpath-19991116';

        return '<ext:UBLExtensions><ext:UBLExtension>'
            .'<ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI>'
            .'<ext:ExtensionContent>'
            .'<sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2"'
            .' xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2"'
            .' xmlns:sbc="urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2">'
            .'<sac:SignatureInformation>'
            .'<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'
            .'<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'
            .'<ds:Signature xmlns:ds="'.self::DS.'" Id="signature">'
            .'<ds:SignedInfo>'
            .'<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            .'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
            .'<ds:Reference Id="invoiceSignedData" URI="">'
            .'<ds:Transforms>'
            .'<ds:Transform Algorithm="'.$xpath.'"><ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath></ds:Transform>'
            .'<ds:Transform Algorithm="'.$xpath.'"><ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath></ds:Transform>'
            .'<ds:Transform Algorithm="'.$xpath.'"><ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath></ds:Transform>'
            .'<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
            .'</ds:Transforms>'
            .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            .'<ds:DigestValue>'.$invoiceDigest.'</ds:DigestValue>'
            .'</ds:Reference>'
            .'<ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'
            .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            .'<ds:DigestValue>'.$propertiesDigest.'</ds:DigestValue>'
            .'</ds:Reference>'
            .'</ds:SignedInfo>'
            .'<ds:SignatureValue>'.$signature.'</ds:SignatureValue>'
            .'<ds:KeyInfo><ds:X509Data><ds:X509Certificate>'.$certificate.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
            .'<ds:Object><xades:QualifyingProperties xmlns:xades="'.self::XADES.'" Target="signature">'.$properties.'</xades:QualifyingProperties></ds:Object>'
            .'</ds:Signature>'
            .'</sac:SignatureInformation>'
            .'</sig:UBLDocumentSignatures>'
            .'</ext:ExtensionContent>'
            .'</ext:UBLExtension></ext:UBLExtensions>';
    }
}
