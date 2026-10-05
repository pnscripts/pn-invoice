<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Unit\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Model\AllowanceCharge;
use Pnscripts\Invoice\Model\DocumentKind;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Line;
use Pnscripts\Invoice\Model\Period;
use Pnscripts\Invoice\Model\TaxCategory;
use Pnscripts\Invoice\Model\VatCategory;
use Pnscripts\Invoice\Tests\Support\SampleInvoices;

final class ModelGuardsTest extends TestCase
{
    public function testStandardRateRequiresARate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaxCategory(VatCategory::StandardRate);
    }

    public function testCategoryOMustNotCarryARate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaxCategory(VatCategory::NotSubjectToVat, '0');
    }

    public function testZeroRatedCategoriesDefaultToZero(): void
    {
        self::assertSame('0', (new TaxCategory(VatCategory::Exempt))->rate?->toString());
        self::assertNull(TaxCategory::notSubject()->rate);
        self::assertSame('S|20', TaxCategory::standard('20.00')->groupKey());
    }

    public function testCategoryProperties(): void
    {
        self::assertTrue(VatCategory::StandardRate->isTaxed());
        self::assertTrue(VatCategory::CanaryIslands->isTaxed());
        self::assertFalse(VatCategory::ReverseCharge->isTaxed());
        self::assertTrue(VatCategory::ReverseCharge->requiresExemptionReason());
        self::assertFalse(VatCategory::ZeroRated->requiresExemptionReason());
    }

    public function testNetPriceMustNotBeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Line('1', 'Item', '1', 'C62', '-1', TaxCategory::standard('20'));
    }

    public function testGrossPriceMustNotBeBelowNetPrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Line('1', 'Item', '1', 'C62', '10', TaxCategory::standard('20'), grossPrice: '9');
    }

    public function testBaseQuantityMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Line('1', 'Item', '1', 'C62', '10', TaxCategory::standard('20'), baseQuantity: '0');
    }

    public function testAllowanceNeedsReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AllowanceCharge::allowance('5');
    }

    public function testInvoiceNeedsLines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invoice('1', new DateTimeImmutable(), 'EUR', SampleInvoices::seller(), SampleInvoices::buyer(), []);
    }

    public function testInvoiceRejectsInvalidCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invoice('1', new DateTimeImmutable(), 'euro', SampleInvoices::seller(), SampleInvoices::buyer(), [
            new Line('1', 'Item', '1', 'C62', '1', TaxCategory::standard('20')),
        ]);
    }

    public function testDocumentLevelAllowanceNeedsVatCategory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Invoice('1', new DateTimeImmutable(), 'EUR', SampleInvoices::seller(), SampleInvoices::buyer(), [
            new Line('1', 'Item', '1', 'C62', '1', TaxCategory::standard('20')),
        ], allowanceCharges: [AllowanceCharge::allowance('1', 'Discount')]);
    }

    public function testPeriodNeedsADate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Period();
    }

    public function testTypeCodeDefaultsFollowTheDocumentKind(): void
    {
        self::assertSame('380', SampleInvoices::standard()->typeCode());
        self::assertSame('381', SampleInvoices::creditNote()->typeCode());
        self::assertSame(DocumentKind::CreditNote, SampleInvoices::creditNote()->kind);
        self::assertCount(1, SampleInvoices::standard()->documentAllowances());
        self::assertCount(1, SampleInvoices::standard()->documentCharges());
    }
}
