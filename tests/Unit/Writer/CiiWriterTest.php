<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Unit\Writer;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Model\DocumentReference;
use PnScripts\Invoice\Model\Invoice;
use PnScripts\Invoice\Model\Specification;
use PnScripts\Invoice\Tests\Support\SampleInvoices;
use PnScripts\Invoice\Tests\Support\XPathAssertions as X;
use PnScripts\Invoice\Writer\CiiWriter;

final class CiiWriterTest extends TestCase
{
    private const string SETTLEMENT = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';

    public function testWritesDocumentContextAndHeader(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::standard(Specification::peppolBis3()));

        self::assertSame(Specification::PEPPOL_BIS_BILLING_3, X::value($document, '//rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'));
        self::assertSame(Specification::PEPPOL_BILLING_PROCESS, X::value($document, '//rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'));
        self::assertSame('INV-2026-0001', X::value($document, '//rsm:ExchangedDocument/ram:ID'));
        self::assertSame('380', X::value($document, '//rsm:ExchangedDocument/ram:TypeCode'));
        self::assertSame('20261001', X::value($document, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'));
        self::assertSame('102', X::value($document, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString/@format'));
    }

    public function testWritesSettlement(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::standard());
        $summation = self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

        self::assertSame('EUR', X::value($document, self::SETTLEMENT . '/ram:InvoiceCurrencyCode'));
        self::assertSame('INV-2026-0001', X::value($document, self::SETTLEMENT . '/ram:PaymentReference'));
        self::assertSame('BG80BNBG96611020345678', X::value($document, self::SETTLEMENT . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID'));
        self::assertSame('BNBGBGSD', X::value($document, self::SETTLEMENT . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID'));
        self::assertSame(3, X::count($document, self::SETTLEMENT . '/ram:ApplicableTradeTax'));
        self::assertSame('20261031', X::value($document, self::SETTLEMENT . '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString'));
        self::assertSame('1302.04', X::value($document, $summation . '/ram:LineTotalAmount'));
        self::assertSame('1307.03', X::value($document, $summation . '/ram:TaxBasisTotalAmount'));
        self::assertSame('247.33', X::value($document, $summation . '/ram:TaxTotalAmount'));
        self::assertSame('EUR', X::value($document, $summation . '/ram:TaxTotalAmount/@currencyID'));
        self::assertSame('1554.36', X::value($document, $summation . '/ram:GrandTotalAmount'));
        self::assertSame('100.00', X::value($document, $summation . '/ram:TotalPrepaidAmount'));
        self::assertSame('1454.36', X::value($document, $summation . '/ram:DuePayableAmount'));
    }

    public function testWritesLines(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::standard());
        $line = '//ram:IncludedSupplyChainTradeLineItem[2]';

        self::assertSame(4, X::count($document, '//ram:IncludedSupplyChainTradeLineItem'));
        self::assertSame('2', X::value($document, $line . '/ram:AssociatedDocumentLineDocument/ram:LineID'));
        self::assertSame('4000862141404', X::value($document, $line . '/ram:SpecifiedTradeProduct/ram:GlobalID'));
        self::assertSame('15.00', X::value($document, $line . '/ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:ChargeAmount'));
        self::assertSame('12.3456', X::value($document, $line . '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'));
        self::assertSame('C62', X::value($document, $line . '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode'));
        self::assertSame('9.00', X::value($document, $line . '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent'));
        self::assertSame('37.04', X::value($document, $line . '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));
    }

    public function testWritesPartiesWithTaxRegistrations(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::reverseCharge());
        $buyer = '//ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';

        self::assertSame('Example Buyer GmbH', X::value($document, $buyer . '/ram:Name'));
        self::assertSame('DE000000000', X::value($document, $buyer . "/ram:SpecifiedTaxRegistration/ram:ID[@schemeID = 'VA']"));
        self::assertSame('DE', X::value($document, $buyer . '/ram:PostalTradeAddress/ram:CountryID'));
        self::assertSame('buyer@example.com', X::value($document, $buyer . '/ram:URIUniversalCommunication/ram:URIID'));
        self::assertSame('Reverse charge', X::value($document, self::SETTLEMENT . '/ram:ApplicableTradeTax/ram:ExemptionReason'));
    }

    public function testWritesCreditNoteAsTypeCode381(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::creditNote());

        self::assertSame('381', X::value($document, '//rsm:ExchangedDocument/ram:TypeCode'));
        self::assertSame('INV-2026-0001', X::value($document, self::SETTLEMENT . '/ram:InvoiceReferencedDocument/ram:IssuerAssignedID'));
        self::assertSame('20261001', X::value($document, self::SETTLEMENT . '/ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'));
    }

    public function testOnlyOnePrecedingInvoiceIsAllowed(): void
    {
        $source = SampleInvoices::creditNote();
        $invoice = new Invoice(
            number: $source->number,
            issueDate: $source->issueDate,
            currency: $source->currency,
            seller: $source->seller,
            buyer: $source->buyer,
            lines: $source->lines,
            kind: $source->kind,
            precedingInvoices: [new DocumentReference('A'), new DocumentReference('B', new DateTimeImmutable('2026-01-01'))],
        );

        $this->expectException(InvalidArgumentException::class);
        (new CiiWriter())->write($invoice);
    }
}
