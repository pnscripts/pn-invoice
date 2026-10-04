<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

final readonly class AllowanceChargeView
{
    public function __construct(
        public string $path,
        public bool $isCharge,
        public Field $amount,
        public Field $reason,
        public Field $reasonCode,
        public Field $vatCategory,
        public Field $vatRate,
    ) {}
}
