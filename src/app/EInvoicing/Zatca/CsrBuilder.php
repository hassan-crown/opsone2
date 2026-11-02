<?php

namespace App\EInvoicing\Zatca;

use RuntimeException;

/**
 * Key pair and certificate signing request of an EGS unit per ZATCA's onboarding spec (ADR-0033): an EC key on
 * secp256k1 and a CSR with C=SA, OU (branch), O (legal name), CN (EGS common name), the certificate template name
 * extension (TSTZATCA- / PREZATCA- / ZATCA-Code-Signing by environment) and the subject alternative name holding the
 * EGS serial (SN), VAT number (UID), invoice types (title, "1100" = standard and simplified), registered address and
 * industry. PHP's OpenSSL only reads extensions from a config file, so one is written to a temp file and removed.
 */
final class CsrBuilder
{
    public const TEMPLATES = [
        'fake' => 'TSTZATCA-Code-Signing',
        'simulation' => 'PREZATCA-Code-Signing',
        'production' => 'ZATCA-Code-Signing',
    ];

    /**
     * @param  array{common_name: string, serial: string, organization: string, unit: string, vat_number: string, address: string, industry: string}  $subject
     * @return array{private_key: string, csr: string}
     */
    public static function generate(array $subject, string $environment): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        if ($key === false) {
            throw new RuntimeException('The EC key could not be generated (secp256k1).');
        }

        $config = tempnam(sys_get_temp_dir(), 'zatca-csr');
        if ($config === false) {
            throw new RuntimeException('No temp file for the CSR configuration.');
        }

        try {
            file_put_contents($config, self::config($subject, self::TEMPLATES[$environment] ?? self::TEMPLATES['production']));
            $csr = openssl_csr_new([
                'countryName' => 'SA',
                'organizationalUnitName' => self::clean($subject['unit']),
                'organizationName' => self::clean($subject['organization']),
                'commonName' => self::clean($subject['common_name']),
            ], $key, ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req']);
            if (! $csr instanceof \OpenSSLCertificateSigningRequest || ! openssl_csr_export($csr, $pem) || ! openssl_pkey_export($key, $private, null, ['config' => $config])) {
                throw new RuntimeException('The CSR could not be created.');
            }
        } finally {
            @unlink($config);
            while (openssl_error_string() !== false) {
                // Drain OpenSSL's error queue (RANDFILE notices) so it never leaks into a later call.
            }
        }

        return ['private_key' => $private, 'csr' => $pem];
    }

    /**
     * The CSR as ZATCA's API takes it: Base64 of the PEM text.
     */
    public static function encoded(string $csrPem): string
    {
        return base64_encode($csrPem);
    }

    /**
     * @param  array{common_name: string, serial: string, organization: string, unit: string, vat_number: string, address: string, industry: string}  $subject
     */
    private static function config(array $subject, string $template): string
    {
        return implode("\n", [
            'oid_section = OIDs',
            '[OIDs]',
            'certificateTemplateName = 1.3.6.1.4.1.311.20.2',
            '[req]',
            'distinguished_name = dn',
            'req_extensions = v3_req',
            'prompt = no',
            'utf8 = yes',
            'string_mask = utf8only',
            '[dn]',
            '[v3_req]',
            'basicConstraints = CA:FALSE',
            'keyUsage = digitalSignature, nonRepudiation, keyEncipherment',
            '1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:'.$template,
            'subjectAltName = dirName:alt_names',
            '[alt_names]',
            'SN = '.self::clean($subject['serial']),
            'UID = '.self::clean($subject['vat_number']),
            'title = 1100',
            'registeredAddress = '.self::clean($subject['address']),
            'businessCategory = '.self::clean($subject['industry']),
            '',
        ]);
    }

    /**
     * OpenSSL config values: one line, no characters that start a comment, a variable or a section.
     */
    private static function clean(string $value): string
    {
        $value = trim((string) preg_replace('/[\r\n#$\[\]=;]+/u', ' ', $value));

        return $value === '' ? '-' : mb_substr($value, 0, 64);
    }
}
