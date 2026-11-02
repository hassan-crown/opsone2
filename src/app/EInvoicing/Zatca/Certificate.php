<?php

namespace App\EInvoicing\Zatca;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;

/**
 * An X.509 certificate as ZATCA hands it out (a CSID's `binarySecurityToken` is the Base64 of the Base64 DER) and the
 * pieces the signature and the QR need: the Base64 body, its digest, issuer name, serial number, public key (DER) and
 * the issuer's signature over it (QR tag 9).
 */
final readonly class Certificate
{
    private function __construct(public string $base64, private string $der) {}

    /**
     * A PEM, a Base64 DER body, or a CSID token (Base64 of that body).
     */
    public static function from(string $value): self
    {
        $value = trim($value);
        if (str_contains($value, '-----BEGIN CERTIFICATE-----')) {
            $value = (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $value);
        }
        $der = base64_decode($value, true);
        if ($der !== false && str_starts_with($der, 'MII')) {
            // A CSID token: the Base64 text of the certificate body, Base64-encoded once more.
            $value = $der;
            $der = base64_decode($value, true);
        }
        if ($der === false || $der === '' || ord($der[0]) !== 0x30) {
            throw new InvalidArgumentException('Not a certificate.');
        }
        $certificate = new self(preg_replace('/\s+/', '', $value) ?? $value, $der);
        if (openssl_x509_parse($certificate->pem()) === false) {
            throw new InvalidArgumentException('Not a certificate.');
        }

        return $certificate;
    }

    public function pem(): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($this->base64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * XAdES CertDigest as ZATCA computes it: Base64 of the hex SHA-256 of the Base64 body text.
     */
    public function digest(): string
    {
        return base64_encode(hash('sha256', $this->base64));
    }

    /**
     * Issuer distinguished name, most specific last (`CN=…, DC=…`), as the X509IssuerName ZATCA expects.
     */
    public function issuerName(): string
    {
        $issuer = $this->parsed()['issuer'] ?? [];
        $parts = [];
        foreach (array_reverse($issuer, true) as $key => $value) {
            foreach (array_reverse((array) $value) as $one) {
                $parts[] = $key.'='.$one;
            }
        }

        return implode(', ', $parts);
    }

    public function serialNumber(): string
    {
        return (string) ($this->parsed()['serialNumber'] ?? '');
    }

    public function publicKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_public($this->pem());
        if ($key === false) {
            throw new InvalidArgumentException('The certificate has no readable public key.');
        }

        return $key;
    }

    /**
     * SubjectPublicKeyInfo DER (QR tag 8).
     */
    public function publicKeyDer(): string
    {
        $pem = (string) (openssl_pkey_get_details($this->publicKey())['key'] ?? '');

        return (string) base64_decode((string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $pem));
    }

    /**
     * The issuer's signature bytes over the certificate (QR tag 9): the last BIT STRING of the outer SEQUENCE.
     */
    public function signature(): string
    {
        [, $offset] = self::header($this->der, 0); // Certificate SEQUENCE
        $children = [];
        while ($offset < strlen($this->der)) {
            [$length, $content] = self::header($this->der, $offset);
            $children[] = [$offset, $content, $length];
            $offset = $content + $length;
        }
        if (count($children) !== 3 || ord($this->der[$children[2][0]]) !== 0x03) {
            throw new InvalidArgumentException('Unexpected certificate structure.');
        }

        // Skip the BIT STRING's "unused bits" byte.
        return substr($this->der, $children[2][1] + 1, $children[2][2] - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function parsed(): array
    {
        $parsed = openssl_x509_parse($this->pem(), false);

        return is_array($parsed) ? self::shortNames($parsed) : [];
    }

    /**
     * openssl_x509_parse(long names) → short RFC 4514 names for the issuer.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private static function shortNames(array $parsed): array
    {
        $short = ['commonName' => 'CN', 'domainComponent' => 'DC', 'organizationName' => 'O', 'organizationalUnitName' => 'OU',
            'countryName' => 'C', 'localityName' => 'L', 'stateOrProvinceName' => 'ST', 'emailAddress' => 'E'];
        $issuer = [];
        foreach ((array) ($parsed['issuer'] ?? []) as $key => $value) {
            $issuer[$short[$key] ?? $key] = $value;
        }
        $parsed['issuer'] = $issuer;

        return $parsed;
    }

    /**
     * DER tag/length header at $offset → [content length, content offset].
     *
     * @return array{int, int}
     */
    private static function header(string $der, int $offset): array
    {
        $first = ord($der[$offset + 1]);
        if ($first < 0x80) {
            return [$first, $offset + 2];
        }
        $bytes = $first & 0x7F;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset + 2 + $i]);
        }

        return [$length, $offset + 2 + $bytes];
    }
}
