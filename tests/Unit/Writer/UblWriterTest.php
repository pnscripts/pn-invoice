<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Unit\Writer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Specification;
use Pnscripts\Invoice\Tests\Support\SampleInvoices;
use Pnscripts\Invoice\Tests\Support\XPathAssertions as X;
use Pnscripts\Invoice\Writer\UblWriter;

final class UblWriterTest extends TestCase
{
    public function testWritesInvoiceHeader(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());

        self::assertSame('Invoice', $document->documentElement?->localName);
        self::assertSame(UblWriter::NS_INVOICE, $document->documentElement->namespaceURI);
        self::assertSame(Specification::EN16931, X::value($document, '/inv:Invoice/cbc:CustomizationID'));
        self::assertSame(0, X::count($document, '/inv:Invoice/cbc:ProfileID'));
        self::assertSame('INV-2026-0001', X::value($document, '/inv:Invoice/cbc:ID'));
        self::assertSame('2026-10-01', X::value($document, '/inv:Invoice/cbc:IssueDate'));
        self::assertSame('2026-10-31', X::value($document, '/inv:Invoice/cbc:DueDate'));
        self::assertSame('380', X::value($document, '/inv:Invoice/cbc:InvoiceTypeCode'));
        self::assertSame('EUR', X::value($document, '/inv:Invoice/cbc:DocumentCurrencyCode'));
        self::assertSame('PO-778', X::value($document, '/inv:Invoice/cbc:BuyerReference'));
    }

    public function testPeppolSpecificationIsConfigurable(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard(Specification::peppolBis3()));

        self::assertSame(Specification::PEPPOL_BIS_BILLING_3, X::value($document, '/inv:Invoice/cbc:CustomizationID'));
        self::assertSame(Specification::PEPPOL_BILLING_PROCESS, X::value($document, '/inv:Invoice/cbc:ProfileID'));
    }

    public function testWritesParties(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $seller = '/inv:Invoice/cac:AccountingSupplierParty/cac:Party';

        self::assertSame('seller@example.com', X::value($document, $seller . '/cbc:EndpointID'));
        self::assertSame('EM', X::value($document, $seller . '/cbc:EndpointID/@schemeID'));
        self::assertSame('Example Supplier Ltd', X::value($document, $seller . '/cac:PartyLegalEntity/cbc:RegistrationName'));
        self::assertSame('BG000000000', X::value($document, $seller . "/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID = 'VAT']/cbc:CompanyID"));
        self::assertSame('BG', X::value($document, $seller . '/cac:PostalAddress/cac:Country/cbc:IdentificationCode'));
        self::assertSame('accounts@example.com', X::value($document, $seller . '/cac:Contact/cbc:ElectronicMail'));
    }

    public function testWritesTotalsAndVatBreakdown(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $totals = '/inv:Invoice/cac:LegalMonetaryTotal';

        self::assertSame('1302.04', X::value($document, $totals . '/cbc:LineExtensionAmount'));
        self::assertSame('10.01', X::value($document, $totals . '/cbc:AllowanceTotalAmount'));
        self::assertSame('15.00', X::value($document, $totals . '/cbc:ChargeTotalAmount'));
        self::assertSame('1307.03', X::value($document, $totals . '/cbc:TaxExclusiveAmount'));
        self::assertSame('1554.36', X::value($document, $totals . '/cbc:TaxInclusiveAmount'));
        self::assertSame('100.00', X::value($document, $totals . '/cbc:PrepaidAmount'));
        self::assertSame('1454.36', X::value($document, $totals . '/cbc:PayableAmount'));
        self::assertSame('EUR', X::value($document, $totals . '/cbc:PayableAmount/@currencyID'));

        self::assertSame('247.33', X::value($document, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
        self::assertSame(3, X::count($document, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal'));
        self::assertSame('20.00', X::value($document, '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[1]/cac:TaxCategory/cbc:Percent'));
    }

    public function testWritesLines(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());

        self::assertSame(4, X::count($document, '/inv:Invoice/cac:InvoiceLine'));
        $line2 = '/inv:Invoice/cac:InvoiceLine[2]';
        self::assertSame('3', X::value($document, $line2 . '/cbc:InvoicedQuantity'));
        self::assertSame('C62', X::value($document, $line2 . '/cbc:InvoicedQuantity/@unitCode'));
        self::assertSame('37.04', X::value($document, $line2 . '/cbc:LineExtensionAmount'));
        self::assertSame('12.3456', X::value($document, $line2 . '/cac:Price/cbc:PriceAmount'));
        self::assertSame('15.00', X::value($document, $line2 . '/cac:Price/cac:AllowanceCharge/cbc:BaseAmount'));
        self::assertSame('2.6544', X::value($document, $line2 . '/cac:Price/cac:AllowanceCharge/cbc:Amount'));
        self::assertSame('0160', X::value($document, $line2 . '/cac:Item/cac:StandardItemIdentification/cbc:ID/@schemeID'));
        self::assertSame('9.00', X::value($document, $line2 . '/cac:Item/cac:ClassifiedTaxCategory/cbc:Percent'));
        self::assertSame('Loyalty discount', X::value($document, '/inv:Invoice/cac:InvoiceLine[3]/cac:AllowanceCharge/cbc:AllowanceChargeReason'));
    }

    public function testWritesCreditNote(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::creditNote());

        self::assertSame(UblWriter::NS_CREDIT_NOTE, $document->documentElement?->namespaceURI);
        self::assertSame('381', X::value($document, '/cn:CreditNote/cbc:CreditNoteTypeCode'));
        self::assertSame('2', X::value($document, '/cn:CreditNote/cac:CreditNoteLine/cbc:CreditedQuantity'));
        self::assertSame('INV-2026-0001', X::value($document, '/cn:CreditNote/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID'));
        // UBL 2.1 CreditNote has no DueDate element; BT-9 goes into PaymentMeans.
        self::assertSame(0, X::count($document, '/cn:CreditNote/cbc:DueDate'));
        self::assertSame('2026-10-14', X::value($document, '/cn:CreditNote/cac:PaymentMeans/cbc:PaymentDueDate'));
    }

    public function testCreditNoteDueDateNeedsPaymentMeans(): void
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
            dueDate: $source->dueDate,
        );

        $this->expectException(InvalidArgumentException::class);
        (new UblWriter())->write($invoice);
    }

    public function testEscapesSpecialCharacters(): void
    {
        $source = SampleInvoices::reverseCharge();
        $invoice = new Invoice(
            number: 'A&B <1>',
            issueDate: $source->issueDate,
            currency: $source->currency,
            seller: $source->seller,
            buyer: $source->buyer,
            lines: $source->lines,
            notes: ['Quote " and apostrophe \' and ampersand &'],
        );

        $xml = (new UblWriter())->write($invoice);
        $document = X::load($xml);

        self::assertStringContainsString('A&amp;B &lt;1&gt;', $xml);
        self::assertSame('A&B <1>', X::value($document, '/inv:Invoice/cbc:ID'));
    }

    public function testReverseChargeExemptionReason(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::reverseCharge());
        $category = '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory';

        self::assertSame('AE', X::value($document, $category . '/cbc:ID'));
        self::assertSame('0.00', X::value($document, $category . '/cbc:Percent'));
        self::assertSame('VATEX-EU-AE', X::value($document, $category . '/cbc:TaxExemptionReasonCode'));
        self::assertSame('Reverse charge', X::value($document, $category . '/cbc:TaxExemptionReason'));
        self::assertSame('0.00', X::value($document, '/inv:Invoice/cac:TaxTotal/cbc:TaxAmount'));
    }
}
