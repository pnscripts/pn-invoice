<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

use InvalidArgumentException;
use Pnscripts\Invoice\Decimal;

/**
 * Document level allowance/charge (BG-20 / BG-21) or invoice line allowance/charge (BG-27 / BG-28).
 *
 * Document level entries must carry a {@see TaxCategory}; line level entries inherit the line's VAT.
 */
final readonly class AllowanceCharge
{
    public Decimal $amount;

    public ?Decimal $baseAmount;

    public ?Decimal $percentage;

    public function __construct(
        public bool $isCharge,
        Decimal|int|string $amount,
        public ?string $reason = null,
        public ?string $reasonCode = null,
        public ?TaxCategory $taxCategory = null,
        Decimal|int|string|null $baseAmount = null,
        Decimal|int|string|null $percentage = null,
    ) {
        if ($reason === null && $reasonCode === null) {
            throw new InvalidArgumentException('An allowance or charge needs a reason or a reason code (BR-33, BR-38, BR-42, BR-44).');
        }

        $this->amount = Decimal::of($amount);
        $this->baseAmount = $baseAmount === null ? null : Decimal::of($baseAmount);
        $this->percentage = $percentage === null ? null : Decimal::of($percentage);
    }

    public static function allowance(
        Decimal|int|string $amount,
        ?string $reason = null,
        ?string $reasonCode = null,
        ?TaxCategory $taxCategory = null,
        Decimal|int|string|null $baseAmount = null,
        Decimal|int|string|null $percentage = null,
    ): self {
        return new self(false, $amount, $reason, $reasonCode, $taxCategory, $baseAmount, $percentage);
    }

    public static function charge(
        Decimal|int|string $amount,
        ?string $reason = null,
        ?string $reasonCode = null,
        ?TaxCategory $taxCategory = null,
        Decimal|int|string|null $baseAmount = null,
        Decimal|int|string|null $percentage = null,
    ): self {
        return new self(true, $amount, $reason, $reasonCode, $taxCategory, $baseAmount, $percentage);
    }
}
