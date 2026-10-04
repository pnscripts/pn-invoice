<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

use InvalidArgumentException;
use PnScripts\Invoice\Decimal;

/**
 * Invoice line (BG-25) including item (BG-31) and price details (BG-29).
 */
final readonly class Line
{
    public Decimal $quantity;

    public Decimal $netPrice;

    public ?Decimal $baseQuantity;

    public ?Decimal $grossPrice;

    /**
     * @param string                $unitCode          UN/ECE Recommendation 20 unit code, e.g. "C62" (one), "HUR" (hour), "KGM"
     * @param list<AllowanceCharge> $allowanceCharges  Line level allowances (BG-27) and charges (BG-28)
     * @param Decimal|int|string    $netPrice          Item net price (BT-146), price after price discount
     * @param Decimal|int|string|null $grossPrice      Item gross price (BT-148), before price discount
     */
    public function __construct(
        public string $id,
        public string $itemName,
        Decimal|int|string $quantity,
        public string $unitCode,
        Decimal|int|string $netPrice,
        public TaxCategory $taxCategory,
        Decimal|int|string|null $baseQuantity = null,
        Decimal|int|string|null $grossPrice = null,
        public ?string $description = null,
        public ?string $sellersItemId = null,
        public ?string $buyersItemId = null,
        public ?Identifier $standardItemId = null,
        public array $allowanceCharges = [],
        public ?Period $period = null,
        public ?string $note = null,
        public ?string $orderLineReference = null,
    ) {
        $this->quantity = Decimal::of($quantity);
        $this->netPrice = Decimal::of($netPrice);
        $this->baseQuantity = $baseQuantity === null ? null : Decimal::of($baseQuantity);
        $this->grossPrice = $grossPrice === null ? null : Decimal::of($grossPrice);

        if ($this->netPrice->isNegative()) {
            throw new InvalidArgumentException('Item net price must not be negative (BR-27); use a negative quantity instead.');
        }
        if ($this->grossPrice !== null && $this->grossPrice->isNegative()) {
            throw new InvalidArgumentException('Item gross price must not be negative (BR-28).');
        }
        if ($this->baseQuantity !== null && !$this->baseQuantity->isPositive()) {
            throw new InvalidArgumentException('Item price base quantity must be greater than zero.');
        }
        if ($this->grossPrice !== null && $this->grossPrice->lessThan($this->netPrice)) {
            throw new InvalidArgumentException('Item gross price must not be lower than the net price.');
        }
    }

    /**
     * Invoice line net amount (BT-131): quantity x (net price / base quantity), rounded to
     * two decimals, plus line charges minus line allowances.
     */
    public function netAmount(): Decimal
    {
        $price = $this->baseQuantity === null ? $this->netPrice : $this->netPrice->div($this->baseQuantity);
        $amount = $this->quantity->mul($price)->round(2);

        foreach ($this->allowanceCharges as $allowanceCharge) {
            $value = $allowanceCharge->amount->round(2);
            $amount = $allowanceCharge->isCharge ? $amount->add($value) : $amount->sub($value);
        }

        return $amount;
    }

    /**
     * Item price discount (BT-147) when a gross price is given.
     */
    public function priceDiscount(): ?Decimal
    {
        return $this->grossPrice?->sub($this->netPrice);
    }
}
