<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Writer\CiiWriter;

/**
 * EN 16931 UN/CEFACT CII D16B syntax binding of {@see InvoiceView}.
 */
final readonly class CiiView implements InvoiceView
{
    private const string SETTLEMENT = 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
    private const string AGREEMENT = 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';

    private XPathReader $x;

    private DOMElement $root;

    private string $vat;

    public function __construct(DOMDocument $document)
    {
        $this->root = $document->documentElement ?? throw new InvalidArgumentException('Empty document.');
        $this->x = new XPathReader($document, [
            'rsm' => CiiWriter::NS_RSM,
            'ram' => CiiWriter::NS_RAM,
            'udt' => CiiWriter::NS_UDT,
            'qdt' => CiiWriter::NS_QDT,
        ]);
        $this->vat = '[' . XPathReader::isVat('ram:TypeCode') . ']';
    }

    public function syntax(): Syntax
    {
        return Syntax::Cii;
    }

    public function rootPath(): string
    {
        return XPathReader::path($this->root);
    }

    public function specificationIdentifier(): Field
    {
        return $this->x->field('rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID', $this->root);
    }

    public function invoiceNumber(): Field
    {
        return $this->x->field('rsm:ExchangedDocument/ram:ID', $this->root);
    }

    public function issueDate(): Field
    {
        return $this->x->field("rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString[@format = '102']", $this->root);
    }

    public function typeCode(): Field
    {
        return $this->x->field('rsm:ExchangedDocument/ram:TypeCode', $this->root);
    }

    public function currencyCode(): Field
    {
        return $this->x->field(self::SETTLEMENT . '/ram:InvoiceCurrencyCode', $this->root);
    }

    public function vatTotal(): Field
    {
        $currency = $this->currencyCode()->normalized();
        $amounts = $this->x->elements(self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount', $this->root);

        foreach ($amounts as $amount) {
            if ($currency === '' || $amount->getAttribute('currencyID') === $currency) {
                return new Field($amount->textContent, XPathReader::path($amount));
            }
        }

        return new Field(null, $this->rootPath() . '/' . self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount');
    }

    public function vatTotalCount(): int
    {
        $currency = $this->currencyCode()->normalized();
        $count = 0;

        foreach ($this->x->elements(self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount', $this->root) as $amount) {
            if ($amount->getAttribute('currencyID') === $currency) {
                ++$count;
            }
        }

        return $count;
    }

    public function seller(): ?PartyView
    {
        return $this->party(self::AGREEMENT . '/ram:SellerTradeParty');
    }

    public function buyer(): ?PartyView
    {
        return $this->party(self::AGREEMENT . '/ram:BuyerTradeParty');
    }

    public function taxRepresentative(): ?PartyView
    {
        return $this->party(self::AGREEMENT . '/ram:SellerTaxRepresentativeTradeParty');
    }

    public function totals(): ?TotalsView
    {
        $element = $this->x->first(self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation', $this->root);
        if (!$element instanceof DOMElement) {
            return null;
        }

        return new TotalsView(
            XPathReader::path($element),
            $this->x->field('ram:LineTotalAmount', $element),
            $this->x->field('ram:AllowanceTotalAmount', $element),
            $this->x->field('ram:ChargeTotalAmount', $element),
            $this->x->field('ram:TaxBasisTotalAmount', $element),
            $this->x->field('ram:GrandTotalAmount', $element),
            $this->x->field('ram:TotalPrepaidAmount', $element),
            $this->x->field('ram:RoundingAmount', $element),
            $this->x->field('ram:DuePayableAmount', $element),
        );
    }

    public function lines(): array
    {
        $lines = [];
        $tax = 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax' . $this->vat;

        foreach ($this->x->elements('rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem', $this->root) as $line) {
            $lines[] = new LineView(
                XPathReader::path($line),
                $this->x->field('ram:AssociatedDocumentLineDocument/ram:LineID', $line),
                $this->x->field('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $line),
                $this->x->field('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode', $line),
                $this->x->field('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line),
                $this->x->field('ram:SpecifiedTradeProduct/ram:Name', $line),
                $this->x->field('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $line),
                $this->x->field('ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:ChargeAmount', $line),
                $this->x->field($tax . '/ram:CategoryCode', $line),
                $this->x->field($tax . '/ram:RateApplicablePercent', $line),
                array_map(
                    fn(DOMElement $e): AllowanceChargeView => $this->allowanceCharge($e),
                    $this->x->elements('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge', $line),
                ),
                $this->period('ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod', $line),
            );
        }

        return $lines;
    }

    public function documentAllowanceCharges(): array
    {
        return array_map(
            fn(DOMElement $e): AllowanceChargeView => $this->allowanceCharge($e),
            $this->x->elements(self::SETTLEMENT . '/ram:SpecifiedTradeAllowanceCharge', $this->root),
        );
    }

    public function vatBreakdowns(): array
    {
        $breakdowns = [];

        foreach ($this->x->elements(self::SETTLEMENT . '/ram:ApplicableTradeTax', $this->root) as $tax) {
            $self = 'self::*' . $this->vat;
            $breakdowns[] = new VatBreakdownView(
                XPathReader::path($tax),
                $this->x->field('ram:BasisAmount', $tax),
                $this->x->field('ram:CalculatedAmount', $tax),
                $this->x->field($self . '/ram:CategoryCode', $tax),
                $this->x->field($self . '/ram:RateApplicablePercent', $tax),
                $this->x->field('ram:ExemptionReason', $tax),
                $this->x->field('ram:ExemptionReasonCode', $tax),
            );
        }

        return $breakdowns;
    }

    public function paymentInstructions(): array
    {
        $instructions = [];

        foreach ($this->x->elements(self::SETTLEMENT . '/ram:SpecifiedTradeSettlementPaymentMeans', $this->root) as $means) {
            $instructions[] = new PaymentInstructionView(
                XPathReader::path($means),
                $this->x->field('ram:TypeCode', $means),
                array_map(
                    fn(DOMElement $account): Field => $this->x->field("ram:IBANID[normalize-space(.) != ''] | ram:ProprietaryID[normalize-space(.) != '']", $account),
                    $this->x->elements('ram:PayeePartyCreditorFinancialAccount', $means),
                ),
            );
        }

        return $instructions;
    }

    public function invoicingPeriod(): ?PeriodView
    {
        return $this->period(self::SETTLEMENT . '/ram:BillingSpecifiedPeriod', $this->root);
    }

    public function precedingInvoiceReferences(): array
    {
        return array_map(
            fn(DOMElement $reference): Field => $this->x->field('ram:IssuerAssignedID', $reference),
            $this->x->elements(self::SETTLEMENT . '/ram:InvoiceReferencedDocument', $this->root),
        );
    }

    private function party(string $expression): ?PartyView
    {
        $element = $this->x->first($expression, $this->root);
        if (!$element instanceof DOMElement) {
            return null;
        }

        $address = $this->x->first('ram:PostalTradeAddress', $element);

        return new PartyView(
            XPathReader::path($element),
            $this->x->field('ram:Name', $element),
            $address === null ? null : XPathReader::path($address),
            $this->x->field('ram:PostalTradeAddress/ram:CountryID', $element),
            $this->x->field("ram:SpecifiedTaxRegistration/ram:ID[@schemeID = 'VA']", $element),
            $this->x->exists("ram:SpecifiedTaxRegistration/ram:ID[@schemeID = 'VA' or @schemeID = 'FC']", $element),
            $this->x->field('ram:SpecifiedLegalOrganization/ram:ID', $element),
            array_map(
                static fn(DOMElement $id): Field => new Field($id->textContent, XPathReader::path($id)),
                $this->x->elements('ram:ID | ram:GlobalID', $element),
            ),
            $this->x->field('ram:URIUniversalCommunication/ram:URIID', $element),
            $this->x->field('ram:URIUniversalCommunication/ram:URIID/@schemeID', $element),
        );
    }

    private function allowanceCharge(DOMElement $element): AllowanceChargeView
    {
        $tax = 'ram:CategoryTradeTax' . $this->vat;
        $indicator = $this->x->field('ram:ChargeIndicator/udt:Indicator', $element)->normalized();

        return new AllowanceChargeView(
            XPathReader::path($element),
            $indicator === 'true' || $indicator === '1',
            $this->x->field('ram:ActualAmount', $element),
            $this->x->field('ram:Reason', $element),
            $this->x->field('ram:ReasonCode', $element),
            $this->x->field($tax . '/ram:CategoryCode', $element),
            $this->x->field($tax . '/ram:RateApplicablePercent', $element),
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
            $this->x->field('ram:StartDateTime/udt:DateTimeString', $period),
            $this->x->field('ram:EndDateTime/udt:DateTimeString', $period),
        );
    }
}
