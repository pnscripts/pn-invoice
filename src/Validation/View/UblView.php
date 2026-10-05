<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Writer\UblWriter;

/**
 * EN 16931 UBL 2.1 syntax binding of {@see InvoiceView}.
 */
final readonly class UblView implements InvoiceView
{
    private XPathReader $x;

    private DOMElement $root;

    private string $vatTaxScheme;

    public function __construct(DOMDocument $document, private Syntax $syntax)
    {
        $this->root = $document->documentElement ?? throw new InvalidArgumentException('Empty document.');
        $this->x = new XPathReader($document, [
            'cac' => UblWriter::NS_CAC,
            'cbc' => UblWriter::NS_CBC,
        ]);
        $this->vatTaxScheme = '[cac:TaxScheme[' . XPathReader::isVat('cbc:ID') . ']]';
    }

    public function syntax(): Syntax
    {
        return $this->syntax;
    }

    public function rootPath(): string
    {
        return XPathReader::path($this->root);
    }

    public function specificationIdentifier(): Field
    {
        return $this->x->field('cbc:CustomizationID', $this->root);
    }

    public function invoiceNumber(): Field
    {
        return $this->x->field('cbc:ID', $this->root);
    }

    public function issueDate(): Field
    {
        return $this->x->field('cbc:IssueDate', $this->root);
    }

    public function typeCode(): Field
    {
        return $this->x->field('cbc:InvoiceTypeCode | cbc:CreditNoteTypeCode', $this->root);
    }

    public function currencyCode(): Field
    {
        return $this->x->field('cbc:DocumentCurrencyCode', $this->root);
    }

    public function vatTotal(): Field
    {
        $currency = $this->currencyCode()->normalized();
        $amounts = $this->x->elements('cac:TaxTotal/cbc:TaxAmount', $this->root);

        foreach ($amounts as $amount) {
            if ($currency === '' || $amount->getAttribute('currencyID') === $currency) {
                return new Field($amount->textContent, XPathReader::path($amount));
            }
        }

        return new Field(null, $this->rootPath() . '/cac:TaxTotal/cbc:TaxAmount');
    }

    public function vatTotalCount(): int
    {
        $currency = $this->currencyCode()->normalized();
        $count = 0;

        foreach ($this->x->elements('cac:TaxTotal/cbc:TaxAmount', $this->root) as $amount) {
            if ($amount->getAttribute('currencyID') === $currency) {
                ++$count;
            }
        }

        return $count;
    }

    public function seller(): ?PartyView
    {
        $element = $this->x->first('cac:AccountingSupplierParty', $this->root);

        return $element instanceof DOMElement ? $this->party($element, 'cac:Party/', 'cac:PartyLegalEntity/cbc:RegistrationName') : null;
    }

    public function buyer(): ?PartyView
    {
        $element = $this->x->first('cac:AccountingCustomerParty', $this->root);

        return $element instanceof DOMElement ? $this->party($element, 'cac:Party/', 'cac:PartyLegalEntity/cbc:RegistrationName') : null;
    }

    public function taxRepresentative(): ?PartyView
    {
        $element = $this->x->first('cac:TaxRepresentativeParty', $this->root);

        return $element instanceof DOMElement ? $this->party($element, '', 'cac:PartyName/cbc:Name') : null;
    }

    public function totals(): ?TotalsView
    {
        $element = $this->x->first('cac:LegalMonetaryTotal', $this->root);
        if (!$element instanceof DOMElement) {
            return null;
        }

        return new TotalsView(
            XPathReader::path($element),
            $this->x->field('cbc:LineExtensionAmount', $element),
            $this->x->field('cbc:AllowanceTotalAmount', $element),
            $this->x->field('cbc:ChargeTotalAmount', $element),
            $this->x->field('cbc:TaxExclusiveAmount', $element),
            $this->x->field('cbc:TaxInclusiveAmount', $element),
            $this->x->field('cbc:PrepaidAmount', $element),
            $this->x->field('cbc:PayableRoundingAmount', $element),
            $this->x->field('cbc:PayableAmount', $element),
        );
    }

    public function lines(): array
    {
        $lines = [];
        $vat = 'cac:Item/cac:ClassifiedTaxCategory' . $this->vatTaxScheme;

        foreach ($this->x->elements('cac:InvoiceLine | cac:CreditNoteLine', $this->root) as $line) {
            $quantity = 'cbc:InvoicedQuantity | cbc:CreditedQuantity';
            $lines[] = new LineView(
                XPathReader::path($line),
                $this->x->field('cbc:ID', $line),
                $this->x->field($quantity, $line),
                $this->x->field('cbc:InvoicedQuantity/@unitCode | cbc:CreditedQuantity/@unitCode', $line),
                $this->x->field('cbc:LineExtensionAmount', $line),
                $this->x->field('cac:Item/cbc:Name', $line),
                $this->x->field('cac:Price/cbc:PriceAmount', $line),
                $this->x->field('cac:Price/cac:AllowanceCharge/cbc:BaseAmount', $line),
                $this->x->field($vat . '/cbc:ID', $line),
                $this->x->field($vat . '/cbc:Percent', $line),
                array_map(fn(DOMElement $e): AllowanceChargeView => $this->allowanceCharge($e), $this->x->elements('cac:AllowanceCharge', $line)),
                $this->period('cac:InvoicePeriod', $line),
            );
        }

        return $lines;
    }

    public function documentAllowanceCharges(): array
    {
        return array_map(
            fn(DOMElement $e): AllowanceChargeView => $this->allowanceCharge($e),
            $this->x->elements('cac:AllowanceCharge', $this->root),
        );
    }

    public function vatBreakdowns(): array
    {
        $breakdowns = [];
        $category = 'cac:TaxCategory' . $this->vatTaxScheme;

        foreach ($this->x->elements('cac:TaxTotal/cac:TaxSubtotal', $this->root) as $subtotal) {
            $breakdowns[] = new VatBreakdownView(
                XPathReader::path($subtotal),
                $this->x->field('cbc:TaxableAmount', $subtotal),
                $this->x->field('cbc:TaxAmount', $subtotal),
                $this->x->field($category . '/cbc:ID', $subtotal),
                $this->x->field($category . '/cbc:Percent', $subtotal),
                $this->x->field($category . '/cbc:TaxExemptionReason', $subtotal),
                $this->x->field($category . '/cbc:TaxExemptionReasonCode', $subtotal),
            );
        }

        return $breakdowns;
    }

    public function paymentInstructions(): array
    {
        $instructions = [];

        foreach ($this->x->elements('cac:PaymentMeans', $this->root) as $means) {
            $instructions[] = new PaymentInstructionView(
                XPathReader::path($means),
                $this->x->field('cbc:PaymentMeansCode', $means),
                array_map(fn(DOMElement $account): Field => $this->x->field('cbc:ID', $account), $this->x->elements('cac:PayeeFinancialAccount', $means)),
            );
        }

        return $instructions;
    }

    public function invoicingPeriod(): ?PeriodView
    {
        return $this->period('cac:InvoicePeriod', $this->root);
    }

    public function precedingInvoiceReferences(): array
    {
        return array_map(
            fn(DOMElement $reference): Field => $this->x->field('cac:InvoiceDocumentReference/cbc:ID', $reference),
            $this->x->elements('cac:BillingReference', $this->root),
        );
    }

    private function party(DOMElement $element, string $prefix, string $nameExpression): PartyView
    {
        $address = $this->x->first($prefix . 'cac:PostalAddress', $element);

        return new PartyView(
            XPathReader::path($element),
            $this->x->field($prefix . $nameExpression, $element),
            $address === null ? null : XPathReader::path($address),
            $this->x->field($prefix . 'cac:PostalAddress/cac:Country/cbc:IdentificationCode', $element),
            $this->x->field($prefix . 'cac:PartyTaxScheme' . $this->vatTaxScheme . '/cbc:CompanyID', $element),
            $this->x->exists($prefix . 'cac:PartyTaxScheme/cbc:CompanyID', $element),
            $this->x->field($prefix . 'cac:PartyLegalEntity/cbc:CompanyID', $element),
            array_map(
                static fn(DOMElement $id): Field => new Field($id->textContent, XPathReader::path($id)),
                $this->x->elements($prefix . "cac:PartyIdentification/cbc:ID[not(@schemeID = 'SEPA')]", $element),
            ),
            $this->x->field($prefix . 'cbc:EndpointID', $element),
            $this->x->field($prefix . 'cbc:EndpointID/@schemeID', $element),
        );
    }

    private function allowanceCharge(DOMElement $element): AllowanceChargeView
    {
        $category = 'cac:TaxCategory' . $this->vatTaxScheme;
        $indicator = $this->x->field('cbc:ChargeIndicator', $element)->normalized();

        return new AllowanceChargeView(
            XPathReader::path($element),
            $indicator === 'true' || $indicator === '1',
            $this->x->field('cbc:Amount', $element),
            $this->x->field('cbc:AllowanceChargeReason', $element),
            $this->x->field('cbc:AllowanceChargeReasonCode', $element),
            $this->x->field($category . '/cbc:ID', $element),
            $this->x->field($category . '/cbc:Percent', $element),
        );
    }

    private function period(string $expression, DOMElement $context): ?PeriodView
    {
        $period = $this->x->first($expression, $context);
        if (!$period instanceof DOMElement) {
            return null;
        }

        return new PeriodView(
            XPathReader::path($period),
            $this->x->field('cbc:StartDate', $period),
            $this->x->field('cbc:EndDate', $period),
            $this->x->exists('cbc:DescriptionCode', $period),
        );
    }
}
