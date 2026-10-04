<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Writer;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PnScripts\Invoice\Calculation\Totals;
use PnScripts\Invoice\Calculation\VatBreakdown;
use PnScripts\Invoice\Model\Address;
use PnScripts\Invoice\Model\AllowanceCharge;
use PnScripts\Invoice\Model\Identifier;
use PnScripts\Invoice\Model\Invoice;
use PnScripts\Invoice\Model\Line;
use PnScripts\Invoice\Model\Party;
use PnScripts\Invoice\Model\PaymentMeans;
use PnScripts\Invoice\Model\Period;
use PnScripts\Invoice\Model\TaxCategory;

/**
 * Writes UN/CEFACT Cross Industry Invoice (CII) D16B documents using the EN 16931 CII syntax binding.
 *
 * Invoices and credit notes share the CrossIndustryInvoice root; only the type code (BT-3) differs.
 */
final class CiiWriter implements InvoiceWriter
{
    public const string NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    public const string NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    public const string NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    public const string NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';

    private DomBuilder $dom;

    private string $currency;

    public function write(Invoice $invoice): string
    {
        $this->toDocument($invoice);

        return $this->dom->toXml();
    }

    public function toDocument(Invoice $invoice): DOMDocument
    {
        if (count($invoice->precedingInvoices) > 1) {
            throw new InvalidArgumentException('CII D16B allows only one preceding invoice reference (BG-3).');
        }

        $this->currency = $invoice->currency;
        $this->dom = new DomBuilder([
            'rsm' => self::NS_RSM,
            'ram' => self::NS_RAM,
            'udt' => self::NS_UDT,
            'qdt' => self::NS_QDT,
        ]);

        $totals = $invoice->totals();
        $root = $this->dom->root('rsm:CrossIndustryInvoice');

        $context = $this->dom->add($root, 'rsm:ExchangedDocumentContext');
        if ($invoice->specification->profileId !== null) {
            $this->dom->add($this->dom->add($context, 'ram:BusinessProcessSpecifiedDocumentContextParameter'), 'ram:ID', $invoice->specification->profileId);
        }
        $this->dom->add($this->dom->add($context, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', $invoice->specification->customizationId);

        $document = $this->dom->add($root, 'rsm:ExchangedDocument');
        $this->dom->add($document, 'ram:ID', $invoice->number);
        $this->dom->add($document, 'ram:TypeCode', $invoice->typeCode());
        $this->dateTime($document, 'ram:IssueDateTime', $invoice->issueDate);
        foreach ($invoice->notes as $note) {
            $this->dom->add($this->dom->add($document, 'ram:IncludedNote'), 'ram:Content', $note);
        }

        $transaction = $this->dom->add($root, 'rsm:SupplyChainTradeTransaction');
        foreach ($invoice->lines as $line) {
            $this->line($transaction, $line);
        }

        $this->agreement($transaction, $invoice);
        $this->delivery($transaction, $invoice);
        $this->settlement($transaction, $invoice, $totals);

        return $this->dom->document;
    }

    private function line(DOMElement $transaction, Line $line): void
    {
        $item = $this->dom->add($transaction, 'ram:IncludedSupplyChainTradeLineItem');

        $lineDocument = $this->dom->add($item, 'ram:AssociatedDocumentLineDocument');
        $this->dom->add($lineDocument, 'ram:LineID', $line->id);
        if ($line->note !== null) {
            $this->dom->add($this->dom->add($lineDocument, 'ram:IncludedNote'), 'ram:Content', $line->note);
        }

        $product = $this->dom->add($item, 'ram:SpecifiedTradeProduct');
        if ($line->standardItemId !== null) {
            $this->dom->add($product, 'ram:GlobalID', $line->standardItemId->value, ['schemeID' => $line->standardItemId->scheme]);
        }
        $this->dom->addOptional($product, 'ram:SellerAssignedID', $line->sellersItemId);
        $this->dom->addOptional($product, 'ram:BuyerAssignedID', $line->buyersItemId);
        $this->dom->add($product, 'ram:Name', $line->itemName);
        $this->dom->addOptional($product, 'ram:Description', $line->description);

        $agreement = $this->dom->add($item, 'ram:SpecifiedLineTradeAgreement');
        if ($line->orderLineReference !== null) {
            $this->dom->add($this->dom->add($agreement, 'ram:BuyerOrderReferencedDocument'), 'ram:LineID', $line->orderLineReference);
        }
        $discount = $line->priceDiscount();
        if ($line->grossPrice !== null && $discount !== null) {
            $gross = $this->dom->add($agreement, 'ram:GrossPriceProductTradePrice');
            $this->dom->add($gross, 'ram:ChargeAmount', Format::price($line->grossPrice));
            if ($line->baseQuantity !== null) {
                $this->dom->add($gross, 'ram:BasisQuantity', Format::number($line->baseQuantity), ['unitCode' => $line->unitCode]);
            }
            if (!$discount->isZero()) {
                $priceAllowance = $this->dom->add($gross, 'ram:AppliedTradeAllowanceCharge');
                $this->dom->add($this->dom->add($priceAllowance, 'ram:ChargeIndicator'), 'udt:Indicator', 'false');
                $this->dom->add($priceAllowance, 'ram:ActualAmount', Format::price($discount));
            }
        }
        $net = $this->dom->add($agreement, 'ram:NetPriceProductTradePrice');
        $this->dom->add($net, 'ram:ChargeAmount', Format::price($line->netPrice));
        if ($line->baseQuantity !== null) {
            $this->dom->add($net, 'ram:BasisQuantity', Format::number($line->baseQuantity), ['unitCode' => $line->unitCode]);
        }

        $delivery = $this->dom->add($item, 'ram:SpecifiedLineTradeDelivery');
        $this->dom->add($delivery, 'ram:BilledQuantity', Format::number($line->quantity), ['unitCode' => $line->unitCode]);

        $settlement = $this->dom->add($item, 'ram:SpecifiedLineTradeSettlement');
        $this->lineTax($settlement, $line->taxCategory);
        if ($line->period !== null) {
            $this->period($settlement, $line->period);
        }
        foreach ($line->allowanceCharges as $allowanceCharge) {
            $this->allowanceCharge($settlement, $allowanceCharge, false);
        }
        $this->dom->add(
            $this->dom->add($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation'),
            'ram:LineTotalAmount',
            Format::amount($line->netAmount()),
        );
    }

    private function agreement(DOMElement $transaction, Invoice $invoice): void
    {
        $agreement = $this->dom->add($transaction, 'ram:ApplicableHeaderTradeAgreement');
        $this->dom->addOptional($agreement, 'ram:BuyerReference', $invoice->buyerReference);
        $this->party($agreement, 'ram:SellerTradeParty', $invoice->seller);
        $this->party($agreement, 'ram:BuyerTradeParty', $invoice->buyer);

        if ($invoice->taxRepresentative !== null) {
            $representative = $this->dom->add($agreement, 'ram:SellerTaxRepresentativeTradeParty');
            $this->dom->add($representative, 'ram:Name', $invoice->taxRepresentative->name);
            if ($invoice->taxRepresentative->address !== null) {
                $this->address($representative, $invoice->taxRepresentative->address);
            }
            if ($invoice->taxRepresentative->vatId !== null) {
                $this->dom->add($this->dom->add($representative, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $invoice->taxRepresentative->vatId, ['schemeID' => 'VA']);
            }
        }

        if ($invoice->orderReference !== null) {
            $this->dom->add($this->dom->add($agreement, 'ram:BuyerOrderReferencedDocument'), 'ram:IssuerAssignedID', $invoice->orderReference);
        }
        if ($invoice->contractReference !== null) {
            $this->dom->add($this->dom->add($agreement, 'ram:ContractReferencedDocument'), 'ram:IssuerAssignedID', $invoice->contractReference);
        }
    }

    private function delivery(DOMElement $transaction, Invoice $invoice): void
    {
        $delivery = $this->dom->add($transaction, 'ram:ApplicableHeaderTradeDelivery');

        if ($invoice->deliveryAddress !== null) {
            $this->address($this->dom->add($delivery, 'ram:ShipToTradeParty'), $invoice->deliveryAddress);
        }

        if ($invoice->deliveryDate !== null) {
            $this->dateTime($this->dom->add($delivery, 'ram:ActualDeliverySupplyChainEvent'), 'ram:OccurrenceDateTime', $invoice->deliveryDate);
        }
    }

    private function settlement(DOMElement $transaction, Invoice $invoice, Totals $totals): void
    {
        $settlement = $this->dom->add($transaction, 'ram:ApplicableHeaderTradeSettlement');

        $remittance = null;
        foreach ($invoice->paymentMeans as $paymentMeans) {
            $remittance ??= $paymentMeans->remittanceInformation;
        }
        $this->dom->addOptional($settlement, 'ram:PaymentReference', $remittance);
        $this->dom->add($settlement, 'ram:InvoiceCurrencyCode', $invoice->currency);

        if ($invoice->payee !== null) {
            $payee = $this->dom->add($settlement, 'ram:PayeeTradeParty');
            $this->identifiers($payee, $invoice->payee->identifiers);
            $this->dom->add($payee, 'ram:Name', $invoice->payee->name);
            if ($invoice->payee->legalRegistrationId !== null) {
                $this->dom->add(
                    $this->dom->add($payee, 'ram:SpecifiedLegalOrganization'),
                    'ram:ID',
                    $invoice->payee->legalRegistrationId->value,
                    ['schemeID' => $invoice->payee->legalRegistrationId->scheme],
                );
            }
        }

        foreach ($invoice->paymentMeans as $paymentMeans) {
            $this->paymentMeans($settlement, $paymentMeans);
        }

        foreach ($totals->vatBreakdown as $breakdown) {
            $this->headerTax($settlement, $breakdown);
        }

        if ($invoice->invoicingPeriod !== null) {
            $this->period($settlement, $invoice->invoicingPeriod);
        }

        foreach ($invoice->allowanceCharges as $allowanceCharge) {
            $this->allowanceCharge($settlement, $allowanceCharge, true);
        }

        if ($invoice->paymentTerms !== null || $invoice->dueDate !== null) {
            $terms = $this->dom->add($settlement, 'ram:SpecifiedTradePaymentTerms');
            $this->dom->addOptional($terms, 'ram:Description', $invoice->paymentTerms);
            if ($invoice->dueDate !== null) {
                $this->dateTime($terms, 'ram:DueDateDateTime', $invoice->dueDate);
            }
        }

        $this->monetarySummation($settlement, $totals);

        foreach ($invoice->precedingInvoices as $reference) {
            $document = $this->dom->add($settlement, 'ram:InvoiceReferencedDocument');
            $this->dom->add($document, 'ram:IssuerAssignedID', $reference->id);
            if ($reference->issueDate !== null) {
                $this->dom->add(
                    $this->dom->add($document, 'ram:FormattedIssueDateTime'),
                    'qdt:DateTimeString',
                    $reference->issueDate->format('Ymd'),
                    ['format' => '102'],
                );
            }
        }

        if ($invoice->accountingCost !== null) {
            $this->dom->add($this->dom->add($settlement, 'ram:ReceivableSpecifiedTradeAccountingAccount'), 'ram:ID', $invoice->accountingCost);
        }
    }

    private function party(DOMElement $parent, string $elementName, Party $party): void
    {
        $element = $this->dom->add($parent, $elementName);

        $this->identifiers($element, $party->identifiers);

        $this->dom->add($element, 'ram:Name', $party->name);
        $this->dom->addOptional($element, 'ram:Description', $party->legalForm);

        if ($party->legalRegistrationId !== null || $party->tradingName !== null) {
            $organization = $this->dom->add($element, 'ram:SpecifiedLegalOrganization');
            if ($party->legalRegistrationId !== null) {
                $this->dom->add($organization, 'ram:ID', $party->legalRegistrationId->value, ['schemeID' => $party->legalRegistrationId->scheme]);
            }
            $this->dom->addOptional($organization, 'ram:TradingBusinessName', $party->tradingName);
        }

        if ($party->contact !== null) {
            $contact = $this->dom->add($element, 'ram:DefinedTradeContact');
            $this->dom->addOptional($contact, 'ram:PersonName', $party->contact->name);
            if ($party->contact->phone !== null) {
                $this->dom->add($this->dom->add($contact, 'ram:TelephoneUniversalCommunication'), 'ram:CompleteNumber', $party->contact->phone);
            }
            if ($party->contact->email !== null) {
                $this->dom->add($this->dom->add($contact, 'ram:EmailURIUniversalCommunication'), 'ram:URIID', $party->contact->email);
            }
        }

        if ($party->address !== null) {
            $this->address($element, $party->address);
        }

        if ($party->electronicAddress !== null) {
            $this->dom->add(
                $this->dom->add($element, 'ram:URIUniversalCommunication'),
                'ram:URIID',
                $party->electronicAddress->value,
                ['schemeID' => $party->electronicAddress->scheme],
            );
        }

        if ($party->vatId !== null) {
            $this->dom->add($this->dom->add($element, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $party->vatId, ['schemeID' => 'VA']);
        }
        if ($party->taxRegistrationId !== null) {
            $this->dom->add($this->dom->add($element, 'ram:SpecifiedTaxRegistration'), 'ram:ID', $party->taxRegistrationId, ['schemeID' => 'FC']);
        }
    }

    /**
     * Identifiers without a scheme go to ID, identifiers with a scheme to GlobalID (EN 16931 CII binding).
     * The schema requires all ID elements before the GlobalID elements.
     *
     * @param list<Identifier> $identifiers
     */
    private function identifiers(DOMElement $party, array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if ($identifier->scheme === null) {
                $this->dom->add($party, 'ram:ID', $identifier->value);
            }
        }

        foreach ($identifiers as $identifier) {
            if ($identifier->scheme !== null) {
                $this->dom->add($party, 'ram:GlobalID', $identifier->value, ['schemeID' => $identifier->scheme]);
            }
        }
    }

    private function address(DOMElement $parent, Address $address): void
    {
        $element = $this->dom->add($parent, 'ram:PostalTradeAddress');
        $this->dom->addOptional($element, 'ram:PostcodeCode', $address->postalCode);
        $this->dom->addOptional($element, 'ram:LineOne', $address->line1);
        $this->dom->addOptional($element, 'ram:LineTwo', $address->line2);
        $this->dom->addOptional($element, 'ram:LineThree', $address->line3);
        $this->dom->addOptional($element, 'ram:CityName', $address->city);
        $this->dom->add($element, 'ram:CountryID', $address->countryCode);
        $this->dom->addOptional($element, 'ram:CountrySubDivisionName', $address->countrySubdivision);
    }

    private function paymentMeans(DOMElement $settlement, PaymentMeans $paymentMeans): void
    {
        $element = $this->dom->add($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans');
        $this->dom->add($element, 'ram:TypeCode', $paymentMeans->typeCode);
        $this->dom->addOptional($element, 'ram:Information', $paymentMeans->typeText);

        $account = $paymentMeans->account;
        if ($account === null) {
            return;
        }

        $creditorAccount = $this->dom->add($element, 'ram:PayeePartyCreditorFinancialAccount');
        $isIban = preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', str_replace(' ', '', $account->id)) === 1;
        if ($isIban) {
            $this->dom->add($creditorAccount, 'ram:IBANID', $account->id);
            $this->dom->addOptional($creditorAccount, 'ram:AccountName', $account->name);
        } else {
            $this->dom->addOptional($creditorAccount, 'ram:AccountName', $account->name);
            $this->dom->add($creditorAccount, 'ram:ProprietaryID', $account->id);
        }

        if ($account->bic !== null) {
            $this->dom->add($this->dom->add($element, 'ram:PayeeSpecifiedCreditorFinancialInstitution'), 'ram:BICID', $account->bic);
        }
    }

    private function lineTax(DOMElement $settlement, TaxCategory $taxCategory): void
    {
        $tax = $this->dom->add($settlement, 'ram:ApplicableTradeTax');
        $this->dom->add($tax, 'ram:TypeCode', 'VAT');
        $this->dom->add($tax, 'ram:CategoryCode', $taxCategory->category->value);
        if ($taxCategory->rate !== null) {
            $this->dom->add($tax, 'ram:RateApplicablePercent', Format::percent($taxCategory->rate));
        }
    }

    private function headerTax(DOMElement $settlement, VatBreakdown $breakdown): void
    {
        $tax = $this->dom->add($settlement, 'ram:ApplicableTradeTax');
        $this->dom->add($tax, 'ram:CalculatedAmount', Format::amount($breakdown->taxAmount));
        $this->dom->add($tax, 'ram:TypeCode', 'VAT');
        $this->dom->addOptional($tax, 'ram:ExemptionReason', $breakdown->exemptionReason);
        $this->dom->add($tax, 'ram:BasisAmount', Format::amount($breakdown->taxableAmount));
        $this->dom->add($tax, 'ram:CategoryCode', $breakdown->category->value);
        $this->dom->addOptional($tax, 'ram:ExemptionReasonCode', $breakdown->exemptionReasonCode);
        if ($breakdown->rate !== null) {
            $this->dom->add($tax, 'ram:RateApplicablePercent', Format::percent($breakdown->rate));
        }
    }

    private function allowanceCharge(DOMElement $parent, AllowanceCharge $allowanceCharge, bool $documentLevel): void
    {
        $element = $this->dom->add($parent, 'ram:SpecifiedTradeAllowanceCharge');
        $this->dom->add($this->dom->add($element, 'ram:ChargeIndicator'), 'udt:Indicator', $allowanceCharge->isCharge ? 'true' : 'false');
        if ($allowanceCharge->percentage !== null) {
            $this->dom->add($element, 'ram:CalculationPercent', Format::number($allowanceCharge->percentage));
        }
        if ($allowanceCharge->baseAmount !== null) {
            $this->dom->add($element, 'ram:BasisAmount', Format::amount($allowanceCharge->baseAmount));
        }
        $this->dom->add($element, 'ram:ActualAmount', Format::amount($allowanceCharge->amount));
        $this->dom->addOptional($element, 'ram:ReasonCode', $allowanceCharge->reasonCode);
        $this->dom->addOptional($element, 'ram:Reason', $allowanceCharge->reason);

        if ($documentLevel && $allowanceCharge->taxCategory !== null) {
            $tax = $this->dom->add($element, 'ram:CategoryTradeTax');
            $this->dom->add($tax, 'ram:TypeCode', 'VAT');
            $this->dom->add($tax, 'ram:CategoryCode', $allowanceCharge->taxCategory->category->value);
            if ($allowanceCharge->taxCategory->rate !== null) {
                $this->dom->add($tax, 'ram:RateApplicablePercent', Format::percent($allowanceCharge->taxCategory->rate));
            }
        }
    }

    private function monetarySummation(DOMElement $settlement, Totals $totals): void
    {
        $summation = $this->dom->add($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->dom->add($summation, 'ram:LineTotalAmount', Format::amount($totals->lineNetTotal));
        if (!$totals->chargeTotal->isZero()) {
            $this->dom->add($summation, 'ram:ChargeTotalAmount', Format::amount($totals->chargeTotal));
        }
        if (!$totals->allowanceTotal->isZero()) {
            $this->dom->add($summation, 'ram:AllowanceTotalAmount', Format::amount($totals->allowanceTotal));
        }
        $this->dom->add($summation, 'ram:TaxBasisTotalAmount', Format::amount($totals->taxExclusiveAmount));
        $this->dom->add($summation, 'ram:TaxTotalAmount', Format::amount($totals->vatTotal), ['currencyID' => $this->currency]);
        if ($totals->roundingAmount !== null) {
            $this->dom->add($summation, 'ram:RoundingAmount', Format::amount($totals->roundingAmount));
        }
        $this->dom->add($summation, 'ram:GrandTotalAmount', Format::amount($totals->taxInclusiveAmount));
        if (!$totals->prepaidAmount->isZero()) {
            $this->dom->add($summation, 'ram:TotalPrepaidAmount', Format::amount($totals->prepaidAmount));
        }
        $this->dom->add($summation, 'ram:DuePayableAmount', Format::amount($totals->payableAmount));
    }

    private function period(DOMElement $parent, Period $period): void
    {
        $element = $this->dom->add($parent, 'ram:BillingSpecifiedPeriod');
        if ($period->start !== null) {
            $this->dateTime($element, 'ram:StartDateTime', $period->start);
        }
        if ($period->end !== null) {
            $this->dateTime($element, 'ram:EndDateTime', $period->end);
        }
    }

    private function dateTime(DOMElement $parent, string $elementName, DateTimeImmutable $date): void
    {
        $this->dom->add($this->dom->add($parent, $elementName), 'udt:DateTimeString', $date->format('Ymd'), ['format' => '102']);
    }
}
