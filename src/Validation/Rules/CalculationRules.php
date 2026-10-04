<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Rules;

use PnScripts\Invoice\Decimal;
use PnScripts\Invoice\Validation\Syntax;
use PnScripts\Invoice\Validation\View\AllowanceChargeView;
use PnScripts\Invoice\Validation\View\InvoiceView;
use PnScripts\Invoice\Validation\View\PartyView;

/**
 * EN 16931 conditions and calculation rules (BR-CO-nn).
 *
 * Arithmetic mirrors the official Schematron: sums are rounded to two decimals before
 * comparison, and BR-CO-17 accepts the same +/- 1 tolerance as the official artefacts.
 */
final class CalculationRules implements RuleSet
{
    /**
     * ISO 3166-1 alpha-2 codes plus the prefixes accepted by the official BR-CO-09 test
     * ("1A" for Kosovo, "EL" for Greece, "XI" for Northern Ireland).
     */
    private const string VAT_PREFIXES = ' 1A AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH EL ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XI YE YT ZA ZM ZW ';

    public function rules(): array
    {
        return [
            'BR-CO-04' => 'Each invoice line needs an invoiced item VAT category code (BT-151).',
            'BR-CO-09' => 'VAT identifier (BT-31, BT-48, BT-63) must start with an ISO 3166-1 alpha-2 country prefix (EL for Greece).',
            'BR-CO-10' => 'Sum of invoice line net amounts (BT-106) does not equal the sum of the line net amounts (BT-131).',
            'BR-CO-11' => 'Sum of document level allowances (BT-107) does not equal the sum of the allowance amounts (BT-92).',
            'BR-CO-12' => 'Sum of document level charges (BT-108) does not equal the sum of the charge amounts (BT-99).',
            'BR-CO-13' => 'Invoice total without VAT (BT-109) must equal BT-106 - BT-107 + BT-108.',
            'BR-CO-14' => 'Invoice total VAT amount (BT-110) must equal the sum of the VAT category tax amounts (BT-117).',
            'BR-CO-15' => 'Invoice total with VAT (BT-112) must equal BT-109 + BT-110.',
            'BR-CO-16' => 'Amount due for payment (BT-115) must equal BT-112 - BT-113 + BT-114.',
            'BR-CO-17' => 'VAT category tax amount (BT-117) must equal taxable amount (BT-116) x rate (BT-119) / 100, rounded to two decimals.',
            'BR-CO-18' => 'The invoice needs at least one VAT breakdown (BG-23).',
            'BR-CO-19' => 'Invoicing period (BG-14) needs a start date (BT-73), an end date (BT-74) or both.',
            'BR-CO-20' => 'Invoice line period (BG-26) needs a start date (BT-134), an end date (BT-135) or both.',
            'BR-CO-21' => 'Document level allowance needs a reason (BT-97), a reason code (BT-98) or both.',
            'BR-CO-22' => 'Document level charge needs a reason (BT-104), a reason code (BT-105) or both.',
            'BR-CO-23' => 'Invoice line allowance needs a reason (BT-139), a reason code (BT-140) or both.',
            'BR-CO-24' => 'Invoice line charge needs a reason (BT-144), a reason code (BT-145) or both.',
            'BR-CO-26' => 'Seller needs a seller identifier (BT-29), a legal registration identifier (BT-30) and/or a VAT identifier (BT-31).',
        ];
    }

    public function check(InvoiceView $view, Report $report): void
    {
        $root = $view->rootPath();

        $lines = $view->lines();
        foreach ($lines as $line) {
            $report->assert($line->vatCategory->exists(), 'BR-CO-04', $line->path);

            if ($line->period !== null) {
                $report->assert($line->period->start->exists() || $line->period->end->exists(), 'BR-CO-20', $line->period->path);
            }

            foreach ($line->allowanceCharges as $allowanceCharge) {
                $report->assert($this->hasReason($allowanceCharge), $allowanceCharge->isCharge ? 'BR-CO-24' : 'BR-CO-23', $allowanceCharge->path);
            }
        }

        $this->vatIdentifiers($view, $report);

        $allowances = [];
        $charges = [];
        foreach ($view->documentAllowanceCharges() as $allowanceCharge) {
            $report->assert($this->hasReason($allowanceCharge), $allowanceCharge->isCharge ? 'BR-CO-22' : 'BR-CO-21', $allowanceCharge->path);
            if ($allowanceCharge->isCharge) {
                $charges[] = $allowanceCharge;
            } else {
                $allowances[] = $allowanceCharge;
            }
        }

        $totals = $view->totals();
        if ($totals !== null) {
            $lineSum = Decimal::zero();
            foreach ($lines as $line) {
                $lineSum = $lineSum->add($line->netAmount->decimalOrZero());
            }

            $lineTotal = $totals->lineNetTotal->decimal();
            if ($totals->lineNetTotal->exists()) {
                $report->assert($lineTotal !== null && $lineTotal->equals($lineSum->round(2)), 'BR-CO-10', $totals->lineNetTotal->path, $this->expected($lineSum->round(2)));
            }

            $this->sumRule($totals->allowanceTotal->decimal(), $totals->allowanceTotal->exists(), $allowances, 'BR-CO-11', $totals->allowanceTotal->path, $report);
            $this->sumRule($totals->chargeTotal->decimal(), $totals->chargeTotal->exists(), $charges, 'BR-CO-12', $totals->chargeTotal->path, $report);

            $taxExclusive = $totals->taxExclusiveAmount->decimal();
            if ($totals->taxExclusiveAmount->exists()) {
                $expected = $totals->lineNetTotal->decimalOrZero()
                    ->sub($totals->allowanceTotal->decimalOrZero())
                    ->add($totals->chargeTotal->decimalOrZero())
                    ->round(2);
                $report->assert($taxExclusive !== null && $taxExclusive->equals($expected), 'BR-CO-13', $totals->taxExclusiveAmount->path, $this->expected($expected));
            }

            if ($totals->taxInclusiveAmount->exists()) {
                $vatTotal = $view->vatTotal()->decimal();
                $taxInclusive = $totals->taxInclusiveAmount->decimal();
                $taxExclusive = $totals->taxExclusiveAmount->decimalOrZero();

                if ($view->vatTotalCount() > 1) {
                    $report->fail('BR-CO-15', $totals->taxInclusiveAmount->path, 'More than one invoice total VAT amount (BT-110) is given in the invoice currency.');
                } elseif ($vatTotal !== null) {
                    $expected = $taxExclusive->add($vatTotal)->round(2);
                    $report->assert($taxInclusive !== null && $taxInclusive->equals($expected), 'BR-CO-15', $totals->taxInclusiveAmount->path, $this->expected($expected));
                } elseif ($view->syntax() === Syntax::Cii) {
                    // CII binding: without a VAT total, BT-112 must equal BT-109.
                    $report->assert($taxInclusive !== null && $taxInclusive->equals($taxExclusive), 'BR-CO-15', $totals->taxInclusiveAmount->path, $this->expected($taxExclusive));
                } else {
                    $report->fail('BR-CO-15', $totals->taxInclusiveAmount->path, 'No invoice total VAT amount (BT-110) in the invoice currency was found.');
                }
            }

            if ($totals->payableAmount->exists()) {
                $payable = $totals->payableAmount->decimal();
                $expected = $totals->taxInclusiveAmount->decimalOrZero()
                    ->sub($totals->prepaidAmount->decimalOrZero())
                    ->add($totals->roundingAmount->decimalOrZero())
                    ->round(2);
                $report->assert($payable !== null && $payable->equals($expected), 'BR-CO-16', $totals->payableAmount->path, $this->expected($expected));
            }
        }

        $breakdowns = $view->vatBreakdowns();
        $report->assert($breakdowns !== [], 'BR-CO-18', $root);

        $vatTotal = $view->vatTotal();
        if ($breakdowns !== [] && $vatTotal->exists()) {
            $sum = Decimal::zero();
            foreach ($breakdowns as $breakdown) {
                $sum = $sum->add($breakdown->taxAmount->decimalOrZero());
            }
            $actual = $vatTotal->decimal();
            $report->assert($actual !== null && $actual->equals($sum->round(2)), 'BR-CO-14', $vatTotal->path, $this->expected($sum->round(2)));
        }

        foreach ($breakdowns as $breakdown) {
            $report->assert(
                self::vatAmountMatches($breakdown->taxableAmount->decimal(), $breakdown->taxAmount->decimal(), $breakdown->rate->decimal(), $view->syntax() === Syntax::Cii),
                'BR-CO-17',
                $breakdown->path,
            );
        }

        $period = $view->invoicingPeriod();
        if ($period !== null) {
            $report->assert($period->start->exists() || $period->end->exists() || $period->hasDescriptionCode, 'BR-CO-19', $period->path);
        }

        $seller = $view->seller();
        if ($seller !== null) {
            $report->assert(
                $seller->vatIdentifier->exists() || $seller->identifiers !== [] || $seller->legalRegistrationId->exists(),
                'BR-CO-26',
                $seller->path,
            );
        }
    }

    /**
     * Official BR-CO-17: zero rate requires a zero tax amount; otherwise |tax| must lie within 1
     * of round(|taxable| x rate / 100, 2). The UBL binding uses a strict bound, the CII binding an
     * inclusive one; both are mirrored here.
     */
    public static function vatAmountMatches(?Decimal $taxable, ?Decimal $tax, ?Decimal $rate, bool $inclusive = false): bool
    {
        if ($tax === null) {
            return false;
        }

        if ($rate === null || $rate->round(0)->isZero()) {
            return $tax->round(0)->isZero();
        }

        if ($taxable === null) {
            return false;
        }

        $expected = $taxable->abs()->mul($rate)->div(100)->round(2);

        $low = $tax->abs()->sub(1)->compare($expected);
        $high = $tax->abs()->add(1)->compare($expected);

        return $inclusive ? ($low <= 0 && $high >= 0) : ($low < 0 && $high > 0);
    }

    /**
     * @param list<AllowanceChargeView> $items
     */
    private function sumRule(?Decimal $total, bool $totalExists, array $items, string $ruleId, string $path, Report $report): void
    {
        if (!$totalExists) {
            $report->assert($items === [], $ruleId, $path, 'The total is missing although allowances/charges exist.');

            return;
        }

        $sum = Decimal::zero();
        foreach ($items as $item) {
            $sum = $sum->add($item->amount->decimalOrZero());
        }

        $report->assert($total !== null && $total->equals($sum->round(2)), $ruleId, $path, $this->expected($sum->round(2)));
    }

    private function vatIdentifiers(InvoiceView $view, Report $report): void
    {
        $parties = array_filter([$view->seller(), $view->buyer(), $view->taxRepresentative()], static fn(?PartyView $p): bool => $p !== null);

        foreach ($parties as $party) {
            if ($party->vatIdentifier->exists()) {
                $prefix = substr($party->vatIdentifier->normalized(), 0, 2);
                $report->assert(strlen($prefix) === 2 && str_contains(self::VAT_PREFIXES, ' ' . $prefix . ' '), 'BR-CO-09', $party->vatIdentifier->path);
            }
        }
    }

    private function hasReason(AllowanceChargeView $allowanceCharge): bool
    {
        return $allowanceCharge->reason->exists() || $allowanceCharge->reasonCode->exists();
    }

    private function expected(Decimal $value): string
    {
        return sprintf('Expected %s.', $value->toFixed(2));
    }
}
