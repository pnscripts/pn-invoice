<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Model\AllowanceCharge;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Line;
use Pnscripts\Invoice\Model\TaxCategory;
use Pnscripts\Invoice\Model\VatCategory;
use Pnscripts\Invoice\Tests\Support\SampleInvoices;

final class InvoiceCalculatorTest extends TestCase
{
    public function testStandardInvoiceTotals(): void
    {
        $totals = SampleInvoices::standard()->totals();

        // 855.00 + 37.04 (3 x 12.3456) + 360.00 (400 - 40) + 50.00
        self::assertSame('1302.04', $totals->lineNetTotal->toFixed(2));
        self::assertSame('10.01', $totals->allowanceTotal->toFixed(2));
        self::assertSame('15.00', $totals->chargeTotal->toFixed(2));
        self::assertSame('1307.03', $totals->taxExclusiveAmount->toFixed(2));
        self::assertSame('247.33', $totals->vatTotal->toFixed(2));
        self::assertSame('1554.36', $totals->taxInclusiveAmount->toFixed(2));
        self::assertSame('100.00', $totals->prepaidAmount->toFixed(2));
        self::assertSame('1454.36', $totals->payableAmount->toFixed(2));
    }

    public function testVatBreakdownGroupsByCategoryAndRate(): void
    {
        $breakdown = SampleInvoices::standard()->totals()->vatBreakdown;

        self::assertCount(3, $breakdown);

        self::assertSame(VatCategory::StandardRate, $breakdown[0]->category);
        self::assertSame('20', $breakdown[0]->rate?->toString());
        self::assertSame('1219.99', $breakdown[0]->taxableAmount->toFixed(2)); // 855 + 360 - 10.01 + 15
        self::assertSame('244.00', $breakdown[0]->taxAmount->toFixed(2));       // 243.998 rounded

        self::assertSame('9', $breakdown[1]->rate?->toString());
        self::assertSame('3.33', $breakdown[1]->taxAmount->toFixed(2));

        self::assertSame(VatCategory::ZeroRated, $breakdown[2]->category);
        self::assertSame('0.00', $breakdown[2]->taxAmount->toFixed(2));
    }

    public function testExemptionReasonIsCarriedToTheBreakdown(): void
    {
        $breakdown = SampleInvoices::exempt()->totals()->vatBreakdown;

        self::assertCount(1, $breakdown);
        self::assertSame('520', $breakdown[0]->taxableAmount->toString());
        self::assertSame('Exempt under the national VAT act', $breakdown[0]->exemptionReason);
        self::assertSame('VATEX-EU-132', $breakdown[0]->exemptionReasonCode);
        self::assertTrue($breakdown[0]->taxAmount->isZero());
    }

    public function testRoundingAmountAdjustsAmountDue(): void
    {
        $invoice = new Invoice(
            number: 'R-1',
            issueDate: new DateTimeImmutable('2026-10-01'),
            currency: 'EUR',
            seller: SampleInvoices::seller(),
            buyer: SampleInvoices::buyer(),
            lines: [new Line('1', 'Item', '1', 'C62', '9.99', TaxCategory::standard('19'))],
            roundingAmount: '0.01',
        );

        $totals = $invoice->totals();

        self::assertSame('11.89', $totals->taxInclusiveAmount->toFixed(2)); // 9.99 + 1.90
        self::assertSame('11.90', $totals->payableAmount->toFixed(2));
    }

    public function testNegativeQuantityProducesNegativeLineAmount(): void
    {
        $line = new Line('1', 'Return', '-2', 'C62', '10', TaxCategory::standard('20'));

        self::assertSame('-20', $line->netAmount()->toString());
    }

    public function testLineChargesAndBaseQuantity(): void
    {
        $line = new Line(
            '1',
            'Paper',
            '2500',
            'C62',
            '4.50',
            TaxCategory::standard('20'),
            baseQuantity: '1000',
            allowanceCharges: [AllowanceCharge::charge('2.5', 'Packing')],
        );

        self::assertSame('13.75', $line->netAmount()->toFixed(2)); // 2500 x 4.50 / 1000 + 2.50
    }
}
