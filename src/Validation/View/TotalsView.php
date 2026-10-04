<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

/**
 * Document totals (BG-22).
 */
final readonly class TotalsView
{
    public function __construct(
        public string $path,
        public Field $lineNetTotal,
        public Field $allowanceTotal,
        public Field $chargeTotal,
        public Field $taxExclusiveAmount,
        public Field $taxInclusiveAmount,
        public Field $prepaidAmount,
        public Field $roundingAmount,
        public Field $payableAmount,
    ) {}
}
