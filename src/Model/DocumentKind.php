<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

/**
 * Selects the syntax root: UBL Invoice vs. UBL CreditNote. In CII both are a
 * CrossIndustryInvoice and only the type code (BT-3) differs.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit-note';

    public function defaultTypeCode(): string
    {
        return $this === self::Invoice ? '380' : '381';
    }
}
