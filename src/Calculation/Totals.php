<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Calculation;

use PnScripts\Invoice\Decimal;

/**
 * Document totals (BG-22) and VAT breakdown (BG-23) derived from an invoice.
 */
final readonly class Totals
{
    /**
     * @param list<VatBreakdown> $vatBreakdown
     */
    public function __construct(
        public Decimal $lineNetTotal,
        public Decimal $allowanceTotal,
        public Decimal $chargeTotal,
        public Decimal $taxExclusiveAmount,
        public Decimal $vatTotal,
        public Decimal $taxInclusiveAmount,
        public Decimal $prepaidAmount,
        public ?Decimal $roundingAmount,
        public Decimal $payableAmount,
        public array $vatBreakdown,
    ) {}
}
