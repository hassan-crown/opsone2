<?php

namespace App\EInvoicing\Zatca;

use InvalidArgumentException;

/**
 * The ZATCA QR payload (ADR-0033): tag-length-value records, one byte tag and one byte length, Base64 of the whole.
 * Phase 1 carries tags 1–5 (seller name, VAT number, timestamp, total with VAT, VAT); phase 2 adds 6 invoice hash,
 * 7 ECDSA signature, 8 public key and 9 (simplified invoices) the signature of the CSID certificate.
 */
final class Tlv
{
    /**
     * @param  array<int, string>  $tags  tag => raw bytes
     */
    public static function encode(array $tags): string
    {
        ksort($tags);
        $out = '';
        foreach ($tags as $tag => $value) {
            $length = strlen($value);
            if ($tag < 1 || $tag > 255 || $length > 255) {
                throw new InvalidArgumentException("TLV tag {$tag} is out of range ({$length} bytes).");
            }
            $out .= chr($tag).chr($length).$value;
        }

        return base64_encode($out);
    }

    /**
     * @return array<int, string> tag => raw bytes
     */
    public static function decode(string $base64): array
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            throw new InvalidArgumentException('The QR payload is not Base64.');
        }
        $tags = [];
        $offset = 0;
        $size = strlen($bytes);
        while ($offset < $size) {
            if ($offset + 2 > $size) {
                throw new InvalidArgumentException('Truncated TLV record.');
            }
            $tag = ord($bytes[$offset]);
            $length = ord($bytes[$offset + 1]);
            if ($offset + 2 + $length > $size) {
                throw new InvalidArgumentException('Truncated TLV value.');
            }
            $tags[$tag] = substr($bytes, $offset + 2, $length);
            $offset += 2 + $length;
        }

        return $tags;
    }
}
