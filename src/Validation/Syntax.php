<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation;

use DOMDocument;
use Pnscripts\Invoice\Writer\CiiWriter;
use Pnscripts\Invoice\Writer\UblWriter;

/**
 * Supported XML syntaxes, detected from the root element.
 */
enum Syntax: string
{
    case UblInvoice = 'ubl-invoice';
    case UblCreditNote = 'ubl-credit-note';
    case Cii = 'cii';

    public static function detect(DOMDocument $document): ?self
    {
        $root = $document->documentElement;
        if ($root === null) {
            return null;
        }

        return match ([$root->namespaceURI, $root->localName]) {
            [UblWriter::NS_INVOICE, 'Invoice'] => self::UblInvoice,
            [UblWriter::NS_CREDIT_NOTE, 'CreditNote'] => self::UblCreditNote,
            [CiiWriter::NS_RSM, 'CrossIndustryInvoice'] => self::Cii,
            default => null,
        };
    }

    public function isUbl(): bool
    {
        return $this !== self::Cii;
    }

    public function label(): string
    {
        return match ($this) {
            self::UblInvoice => 'UBL 2.1 Invoice',
            self::UblCreditNote => 'UBL 2.1 CreditNote',
            self::Cii => 'UN/CEFACT CII D16B',
        };
    }
}
