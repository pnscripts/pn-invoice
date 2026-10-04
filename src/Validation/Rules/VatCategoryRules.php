<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Rules;

use InvalidArgumentException;
use PnScripts\Invoice\Decimal;
use PnScripts\Invoice\Model\VatCategory;
use PnScripts\Invoice\Validation\View\AllowanceChargeView;
use PnScripts\Invoice\Validation\View\InvoiceView;
use PnScripts\Invoice\Validation\View\LineView;

/**
 * VAT category rules BR-{S,Z,E,AE}-01 .. -10, implemented once and parameterised by category.
 *
 * Supported categories: S (standard rate), Z (zero rated), E (exempt), AE (reverse charge).
 * The rate itself is never hard-coded: rules only check the relationships EN 16931 defines
 * (rate > 0 for S, rate = 0 for Z/E/AE, breakdown sums, exemption reasons).
 */
final readonly class VatCategoryRules implements RuleSet
{
    private string $prefix;

    public function __construct(private VatCategory $category)
    {
        $this->prefix = 'BR-' . $category->value . '-';

        if (!in_array($category, self::supported(), true)) {
            throw new InvalidArgumentException(sprintf('VAT category rules for "%s" are not implemented yet.', $category->value));
        }
    }

    /**
     * @return list<VatCategory>
     */
    public static function supported(): array
    {
        return [VatCategory::StandardRate, VatCategory::ZeroRated, VatCategory::Exempt, VatCategory::ReverseCharge];
    }

    public function rules(): array
    {
        $code = $this->category->value;
        $rateCondition = $this->category === VatCategory::StandardRate ? 'greater than zero' : '0 (zero)';
        $parties = 'the seller VAT identifier (BT-31), seller tax registration identifier (BT-32) or tax representative VAT identifier (BT-63)';
        if ($this->category === VatCategory::ReverseCharge) {
            $parties .= ', and the buyer VAT identifier (BT-48) or buyer legal registration identifier (BT-47)';
        }

        return [
            $this->prefix . '01' => $this->category === VatCategory::StandardRate
                ? sprintf('Lines, allowances or charges with VAT category "%s" require at least one "%s" VAT breakdown, and vice versa.', $code, $code)
                : sprintf('Lines, allowances or charges with VAT category "%s" require exactly one "%s" VAT breakdown.', $code, $code),
            $this->prefix . '02' => sprintf('An invoice line with VAT category "%s" requires %s.', $code, $parties),
            $this->prefix . '03' => sprintf('A document level allowance with VAT category "%s" requires %s.', $code, $parties),
            $this->prefix . '04' => sprintf('A document level charge with VAT category "%s" requires %s.', $code, $parties),
            $this->prefix . '05' => sprintf('Invoiced item VAT rate (BT-152) for category "%s" must be %s.', $code, $rateCondition),
            $this->prefix . '06' => sprintf('Document level allowance VAT rate (BT-96) for category "%s" must be %s.', $code, $rateCondition),
            $this->prefix . '07' => sprintf('Document level charge VAT rate (BT-103) for category "%s" must be %s.', $code, $rateCondition),
            $this->prefix . '08' => sprintf('Taxable amount (BT-116) of the "%s" VAT breakdown must equal the line net amounts plus charges minus allowances of that category%s.', $code, $this->category === VatCategory::StandardRate ? ' and rate' : ''),
            $this->prefix . '09' => $this->category === VatCategory::StandardRate
                ? 'VAT category tax amount (BT-117) of the "S" breakdown must equal taxable amount x rate / 100.'
                : sprintf('VAT category tax amount (BT-117) of the "%s" breakdown must be 0 (zero).', $code),
            $this->prefix . '10' => $this->category->requiresExemptionReason()
                ? sprintf('The "%s" VAT breakdown needs an exemption reason (BT-120) or exemption reason code (BT-121).', $code)
                : sprintf('The "%s" VAT breakdown must not have an exemption reason (BT-120) or exemption reason code (BT-121).', $code),
        ];
    }

    public function check(InvoiceView $view, Report $report): void
    {
        $lines = array_values(array_filter($view->lines(), fn(LineView $line): bool => $this->matches($line->vatCategory->normalized())));
        $allowanceCharges = array_values(array_filter($view->documentAllowanceCharges(), fn(AllowanceChargeView $ac): bool => $this->matches($ac->vatCategory->normalized())));
        $allowances = array_values(array_filter($allowanceCharges, static fn(AllowanceChargeView $ac): bool => !$ac->isCharge));
        $charges = array_values(array_filter($allowanceCharges, static fn(AllowanceChargeView $ac): bool => $ac->isCharge));
        $allBreakdowns = $view->vatBreakdowns();
        $breakdowns = array_values(array_filter($allBreakdowns, fn($b): bool => $this->matches($b->category->normalized())));

        $this->rule01($view, $report, count($lines) + count($allowanceCharges), count($breakdowns));
        $this->registrationRules($view, $report, $lines, $allowances, $charges);

        foreach ($lines as $line) {
            $report->assert($this->rateIsValid($line->vatRate->decimal()), $this->prefix . '05', $line->vatRate->path);
        }
        foreach ($allowances as $allowance) {
            $report->assert($this->rateIsValid($allowance->vatRate->decimal()), $this->prefix . '06', $allowance->vatRate->path);
        }
        foreach ($charges as $charge) {
            $report->assert($this->rateIsValid($charge->vatRate->decimal()), $this->prefix . '07', $charge->vatRate->path);
        }

        foreach ($breakdowns as $breakdown) {
            $rate = $breakdown->rate->decimal();
            $taxable = $breakdown->taxableAmount->decimal();
            $tax = $breakdown->taxAmount->decimal();

            // -08: taxable amount equals the category's (and for S: rate's) line, charge and allowance sums.
            if ($this->category === VatCategory::StandardRate) {
                if ($rate !== null) {
                    $expected = $this->sumFor($lines, $allowances, $charges, $rate);
                    $used = $expected['used'];
                    $ok = $used && $taxable !== null
                        && $taxable->sub(1)->lessThan($expected['sum'])
                        && $taxable->add(1)->greaterThan($expected['sum']);
                    $report->assert($ok, $this->prefix . '08', $breakdown->taxableAmount->path, sprintf('Expected %s.', $expected['sum']->toFixed(2)));
                }
            } else {
                $expected = $this->sumFor($lines, $allowances, $charges, null)['sum'];
                $report->assert($taxable !== null && $taxable->equals($expected), $this->prefix . '08', $breakdown->taxableAmount->path, sprintf('Expected %s.', $expected->toFixed(2)));
            }

            // -09: tax amount.
            if ($this->category === VatCategory::StandardRate) {
                $ok = false;
                if ($tax !== null && $taxable !== null && $rate !== null) {
                    $calculated = $taxable->abs()->mul($rate)->div(100)->round(2);
                    $ok = $tax->abs()->sub(1)->lessThan($calculated) && $tax->abs()->add(1)->greaterThan($calculated);
                }
                $report->assert($ok, $this->prefix . '09', $breakdown->taxAmount->path);
            } else {
                $report->assert($tax !== null && $tax->isZero(), $this->prefix . '09', $breakdown->taxAmount->path);
            }

            // -10: exemption reason.
            $hasReason = $breakdown->exemptionReason->exists() || $breakdown->exemptionReasonCode->exists();
            $report->assert($this->category->requiresExemptionReason() ? $hasReason : !$hasReason, $this->prefix . '10', $breakdown->path);
        }
    }

    private function rule01(InvoiceView $view, Report $report, int $usages, int $breakdowns): void
    {
        if ($this->category === VatCategory::StandardRate) {
            $report->assert(($usages > 0) === ($breakdowns > 0), $this->prefix . '01', $view->rootPath());

            return;
        }

        if ($usages > 0 || $breakdowns > 0) {
            $report->assert($breakdowns === 1, $this->prefix . '01', $view->rootPath());
        }
    }

    /**
     * @param list<LineView>            $lines
     * @param list<AllowanceChargeView> $allowances
     * @param list<AllowanceChargeView> $charges
     */
    private function registrationRules(InvoiceView $view, Report $report, array $lines, array $allowances, array $charges): void
    {
        if ($lines === [] && $allowances === [] && $charges === []) {
            return;
        }

        $seller = $view->seller();
        $representative = $view->taxRepresentative();
        $registered = ($seller !== null && $seller->hasAnyTaxRegistration)
            || ($representative !== null && $representative->vatIdentifier->exists());

        if ($this->category === VatCategory::ReverseCharge) {
            $buyer = $view->buyer();
            $registered = $registered && $buyer !== null && ($buyer->vatIdentifier->exists() || $buyer->legalRegistrationId->exists());
        }

        $location = $seller !== null ? $seller->path : $view->rootPath();
        if ($lines !== []) {
            $report->assert($registered, $this->prefix . '02', $location);
        }
        if ($allowances !== []) {
            $report->assert($registered, $this->prefix . '03', $location);
        }
        if ($charges !== []) {
            $report->assert($registered, $this->prefix . '04', $location);
        }
    }

    private function rateIsValid(?Decimal $rate): bool
    {
        if ($rate === null) {
            return false;
        }

        return $this->category === VatCategory::StandardRate ? $rate->isPositive() : $rate->isZero();
    }

    /**
     * @param list<LineView>            $lines
     * @param list<AllowanceChargeView> $allowances
     * @param list<AllowanceChargeView> $charges
     *
     * @return array{sum: Decimal, used: bool}
     */
    private function sumFor(array $lines, array $allowances, array $charges, ?Decimal $rate): array
    {
        $sum = Decimal::zero();
        $used = false;
        $sameRate = static fn(?Decimal $itemRate): bool => $rate === null || ($itemRate !== null && $itemRate->equals($rate));

        foreach ($lines as $line) {
            if ($sameRate($line->vatRate->decimal())) {
                $sum = $sum->add($line->netAmount->decimalOrZero());
                $used = true;
            }
        }
        foreach ($charges as $charge) {
            if ($sameRate($charge->vatRate->decimal())) {
                $sum = $sum->add($charge->amount->decimalOrZero());
                $used = true;
            }
        }
        foreach ($allowances as $allowance) {
            if ($sameRate($allowance->vatRate->decimal())) {
                $sum = $sum->sub($allowance->amount->decimalOrZero());
                $used = true;
            }
        }

        return ['sum' => $sum, 'used' => $used];
    }

    private function matches(string $code): bool
    {
        return $code === $this->category->value;
    }
}
