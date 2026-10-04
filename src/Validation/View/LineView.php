<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

final readonly class LineView
{
    /**
     * @param list<AllowanceChargeView> $allowanceCharges
     */
    public function __construct(
        public string $path,
        public Field $id,
        public Field $quantity,
        public Field $unitCode,
        public Field $netAmount,
        public Field $itemName,
        public Field $netPrice,
        public Field $grossPrice,
        public Field $vatCategory,
        public Field $vatRate,
        public array $allowanceCharges,
        public ?PeriodView $period,
    ) {}
}
