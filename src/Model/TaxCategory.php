<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

use InvalidArgumentException;
use Pnscripts\Invoice\Decimal;

/**
 * VAT classification of a line, allowance or charge (BT-151/152, BT-95/96, BT-102/103)
 * plus the exemption reason that ends up in the VAT breakdown (BT-120/121).
 */
final readonly class TaxCategory
{
    public ?Decimal $rate;

    public function __construct(
        public VatCategory $category,
        Decimal|int|string|null $rate = null,
        public ?string $exemptionReason = null,
        public ?string $exemptionReasonCode = null,
    ) {
        if ($rate !== null && !$category->hasRate()) {
            throw new InvalidArgumentException('VAT category "O" (not subject to VAT) must not carry a rate.');
        }

        if ($rate === null && $category->hasRate()) {
            // Categories other than S, L and M are always 0 %; default it so callers need not repeat it.
            if ($category->isTaxed()) {
                throw new InvalidArgumentException(sprintf('VAT category "%s" requires a rate.', $category->value));
            }
            $rate = 0;
        }

        $this->rate = $rate === null ? null : Decimal::of($rate);
    }

    public static function standard(Decimal|int|string $rate): self
    {
        return new self(VatCategory::StandardRate, $rate);
    }

    public static function zeroRated(): self
    {
        return new self(VatCategory::ZeroRated, 0);
    }

    public static function exempt(?string $reason, ?string $reasonCode = null): self
    {
        return new self(VatCategory::Exempt, 0, $reason, $reasonCode);
    }

    public static function reverseCharge(string $reason = 'Reverse charge', ?string $reasonCode = 'VATEX-EU-AE'): self
    {
        return new self(VatCategory::ReverseCharge, 0, $reason, $reasonCode);
    }

    public static function intraCommunity(string $reason = 'Intra-community supply', ?string $reasonCode = 'VATEX-EU-IC'): self
    {
        return new self(VatCategory::IntraCommunity, 0, $reason, $reasonCode);
    }

    public static function export(string $reason = 'Export outside the EU', ?string $reasonCode = 'VATEX-EU-G'): self
    {
        return new self(VatCategory::Export, 0, $reason, $reasonCode);
    }

    public static function notSubject(string $reason = 'Not subject to VAT', ?string $reasonCode = 'VATEX-EU-O'): self
    {
        return new self(VatCategory::NotSubjectToVat, null, $reason, $reasonCode);
    }

    /**
     * Key used to group lines, allowances and charges into one VAT breakdown (BG-23).
     */
    public function groupKey(): string
    {
        return $this->category->value . '|' . ($this->rate?->toString() ?? '-');
    }
}
