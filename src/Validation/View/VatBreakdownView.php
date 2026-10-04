<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

final readonly class VatBreakdownView
{
    public function __construct(
        public string $path,
        public Field $taxableAmount,
        public Field $taxAmount,
        public Field $category,
        public Field $rate,
        public Field $exemptionReason,
        public Field $exemptionReasonCode,
    ) {}
}
