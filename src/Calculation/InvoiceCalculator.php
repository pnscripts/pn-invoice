<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Calculation;

use LogicException;
use PnScripts\Invoice\Decimal;
use PnScripts\Invoice\Model\Invoice;
use PnScripts\Invoice\Model\TaxCategory;

/**
 * Derives document totals and the VAT breakdown following EN 16931 calculation rules
 * (BR-CO-10 .. BR-CO-17, BR-S-08, BR-Z-08, BR-E-08, BR-AE-08 and siblings).
 *
 * VAT is calculated per VAT category and rate on the summed taxable amount and then
 * rounded to two decimals (half away from zero), which is the method EN 16931 assumes.
 */
final class InvoiceCalculator
{
    public function calculate(Invoice $invoice): Totals
    {
        /** @var array<string, array{category: TaxCategory, taxable: Decimal, reason: ?string, reasonCode: ?string}> $groups */
        $groups = [];

        $lineNetTotal = Decimal::zero();
        foreach ($invoice->lines as $line) {
            $net = $line->netAmount();
            $lineNetTotal = $lineNetTotal->add($net);
            $this->addToGroup($groups, $line->taxCategory, $net);
        }

        $allowanceTotal = Decimal::zero();
        $chargeTotal = Decimal::zero();
        foreach ($invoice->allowanceCharges as $allowanceCharge) {
            $amount = $allowanceCharge->amount->round(2);
            // The constructor of Invoice guarantees a tax category on document level entries.
            $taxCategory = $allowanceCharge->taxCategory ?? throw new LogicException('Missing VAT category.');

            if ($allowanceCharge->isCharge) {
                $chargeTotal = $chargeTotal->add($amount);
                $this->addToGroup($groups, $taxCategory, $amount);
            } else {
                $allowanceTotal = $allowanceTotal->add($amount);
                $this->addToGroup($groups, $taxCategory, $amount->negate());
            }
        }

        $breakdown = [];
        $vatTotal = Decimal::zero();
        foreach ($groups as $group) {
            $category = $group['category'];
            $taxAmount = $category->category->isTaxed() && $category->rate !== null
                ? $group['taxable']->mul($category->rate)->div(100)->round(2)
                : Decimal::zero();
            $vatTotal = $vatTotal->add($taxAmount);

            $breakdown[] = new VatBreakdown(
                $category->category,
                $category->rate,
                $group['taxable'],
                $taxAmount,
                $group['reason'],
                $group['reasonCode'],
            );
        }

        $taxExclusive = $lineNetTotal->sub($allowanceTotal)->add($chargeTotal);
        $taxInclusive = $taxExclusive->add($vatTotal);
        $prepaid = $invoice->prepaidAmount->round(2);
        $rounding = $invoice->roundingAmount?->round(2);
        $payable = $taxInclusive->sub($prepaid)->add($rounding ?? Decimal::zero());

        return new Totals(
            $lineNetTotal,
            $allowanceTotal,
            $chargeTotal,
            $taxExclusive,
            $vatTotal,
            $taxInclusive,
            $prepaid,
            $rounding,
            $payable,
            $breakdown,
        );
    }

    /**
     * @param array<string, array{category: TaxCategory, taxable: Decimal, reason: ?string, reasonCode: ?string}> $groups
     */
    private function addToGroup(array &$groups, TaxCategory $category, Decimal $amount): void
    {
        $key = $category->groupKey();

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'category' => $category,
                'taxable' => Decimal::zero(),
                'reason' => null,
                'reasonCode' => null,
            ];
        }

        $groups[$key]['taxable'] = $groups[$key]['taxable']->add($amount);
        $groups[$key]['reason'] ??= $category->exemptionReason;
        $groups[$key]['reasonCode'] ??= $category->exemptionReasonCode;
    }
}
