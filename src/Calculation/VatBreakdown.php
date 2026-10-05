<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Calculation;

use Pnscripts\Invoice\Decimal;
use Pnscripts\Invoice\Model\VatCategory;

/**
 * VAT breakdown (BG-23) for one VAT category and rate.
 */
final readonly class VatBreakdown
{
    public function __construct(
        public VatCategory $category,
        public ?Decimal $rate,
        public Decimal $taxableAmount,
        public Decimal $taxAmount,
        public ?string $exemptionReason = null,
        public ?string $exemptionReasonCode = null,
    ) {}
}
