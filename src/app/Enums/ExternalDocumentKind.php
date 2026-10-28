<?php

namespace App\Enums;

/**
 * The type the external accounting system gave the document (SRS: «ضريبية أو مبسطة»); OpsOne only records it.
 */
enum ExternalDocumentKind: string
{
    case TaxInvoice = 'tax_invoice';
    case SimplifiedInvoice = 'simplified_invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
}
