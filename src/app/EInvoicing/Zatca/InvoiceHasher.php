<?php

namespace App\EInvoicing\Zatca;

use DOMDocument;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * The invoice hash ZATCA chains and signs (ADR-0033): drop `ext:UBLExtensions`, `cac:Signature` and the QR
 * `AdditionalDocumentReference`, canonicalize, SHA-256, Base64 of the binary digest. The hash of "0" (hex, Base64)
 * starts every chain.
 */
final class InvoiceHasher
{
    /** Base64 of the hex SHA-256 of "0": the PIH of the first invoice of a device. */
    public const FIRST_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public static function hash(string $xml): string
    {
        return base64_encode(hash('sha256', self::canonical($xml), true));
    }

    public static function canonical(string $xml): string
    {
        $doc = self::load($xml);
        $xpath = self::xpath($doc);
        foreach (['//ext:UBLExtensions', '//cac:Signature', "//cac:AdditionalDocumentReference[cbc:ID='QR']"] as $query) {
            $nodes = $xpath->query($query);
            foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
                if ($node instanceof DOMNode) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $canonical = $doc->documentElement?->C14N(false, false);
        if (! is_string($canonical)) {
            throw new InvalidArgumentException('The invoice XML cannot be canonicalized.');
        }

        return $canonical;
    }

    public static function load(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = true;
        if ($xml === '' || ! @$doc->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException('Not well-formed XML.');
        }

        return $doc;
    }

    /**
     * The trimmed text of the first node matching $query, or null.
     */
    public static function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? trim((string) $node->textContent) : null;
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('inv', UblInvoice::NS);
        $xpath->registerNamespace('cac', UblInvoice::CAC);
        $xpath->registerNamespace('cbc', UblInvoice::CBC);
        $xpath->registerNamespace('ext', UblInvoice::EXT);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        return $xpath;
    }
}
