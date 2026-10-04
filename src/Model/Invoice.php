<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use PnScripts\Invoice\Calculation\InvoiceCalculator;
use PnScripts\Invoice\Calculation\Totals;
use PnScripts\Invoice\Decimal;

/**
 * Syntax-neutral invoice or credit note covering the EN 16931 core used in typical B2B invoicing.
 *
 * Totals and the VAT breakdown are always derived from lines and allowances/charges
 * (see {@see InvoiceCalculator}); they cannot be set by hand, which keeps the
 * calculation rules BR-CO-10..BR-CO-17 satisfied by construction.
 */
final readonly class Invoice
{
    public Decimal $prepaidAmount;

    public ?Decimal $roundingAmount;

    /**
     * @param list<Line>              $lines
     * @param list<AllowanceCharge>   $allowanceCharges Document level allowances (BG-20) and charges (BG-21)
     * @param list<PaymentMeans>      $paymentMeans
     * @param list<string>            $notes            Invoice notes (BT-22)
     * @param list<DocumentReference> $precedingInvoices Preceding invoice references (BG-3)
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issueDate,
        public string $currency,
        public Party $seller,
        public Party $buyer,
        public array $lines,
        public DocumentKind $kind = DocumentKind::Invoice,
        public ?string $typeCode = null,
        public Specification $specification = new Specification(),
        public ?DateTimeImmutable $dueDate = null,
        public ?string $buyerReference = null,
        public ?string $orderReference = null,
        public ?string $contractReference = null,
        public ?string $accountingCost = null,
        public array $notes = [],
        public ?DateTimeImmutable $taxPointDate = null,
        public ?Period $invoicingPeriod = null,
        public array $precedingInvoices = [],
        public ?Party $payee = null,
        public ?Party $taxRepresentative = null,
        public ?DateTimeImmutable $deliveryDate = null,
        public ?Address $deliveryAddress = null,
        public array $paymentMeans = [],
        public ?string $paymentTerms = null,
        public array $allowanceCharges = [],
        Decimal|int|string $prepaidAmount = 0,
        Decimal|int|string|null $roundingAmount = null,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('Currency "%s" must be an ISO 4217 alpha-3 code.', $currency));
        }
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line (BR-16).');
        }
        foreach ($allowanceCharges as $allowanceCharge) {
            if ($allowanceCharge->taxCategory === null) {
                throw new InvalidArgumentException('Document level allowances and charges need a VAT category (BR-32, BR-37).');
            }
        }

        $this->prepaidAmount = Decimal::of($prepaidAmount);
        $this->roundingAmount = $roundingAmount === null ? null : Decimal::of($roundingAmount);
    }

    /**
     * Invoice type code (BT-3), UNTDID 1001. Defaults to 380 (invoice) / 381 (credit note).
     */
    public function typeCode(): string
    {
        return $this->typeCode ?? $this->kind->defaultTypeCode();
    }

    public function totals(): Totals
    {
        return (new InvoiceCalculator())->calculate($this);
    }

    /**
     * @return list<AllowanceCharge>
     */
    public function documentAllowances(): array
    {
        return array_values(array_filter($this->allowanceCharges, static fn(AllowanceCharge $ac): bool => !$ac->isCharge));
    }

    /**
     * @return list<AllowanceCharge>
     */
    public function documentCharges(): array
    {
        return array_values(array_filter($this->allowanceCharges, static fn(AllowanceCharge $ac): bool => $ac->isCharge));
    }
}
