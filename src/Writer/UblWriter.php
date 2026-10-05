<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Writer;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Pnscripts\Invoice\Calculation\Totals;
use Pnscripts\Invoice\Calculation\VatBreakdown;
use Pnscripts\Invoice\Model\Address;
use Pnscripts\Invoice\Model\AllowanceCharge;
use Pnscripts\Invoice\Model\DocumentKind;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Line;
use Pnscripts\Invoice\Model\Party;
use Pnscripts\Invoice\Model\PaymentMeans;
use Pnscripts\Invoice\Model\Period;
use Pnscripts\Invoice\Model\TaxCategory;

/**
 * Writes OASIS UBL 2.1 Invoice or CreditNote documents using the EN 16931 UBL syntax binding.
 *
 * The specification identifier (CustomizationID) and business process (ProfileID) come from
 * {@see Invoice::$specification}, so the same writer produces plain EN 16931 or Peppol BIS
 * Billing 3.0 identifiers.
 */
final class UblWriter implements InvoiceWriter
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private DomBuilder $dom;

    private string $currency;

    public function write(Invoice $invoice): string
    {
        $this->toDocument($invoice);

        return $this->dom->toXml();
    }

    public function toDocument(Invoice $invoice): DOMDocument
    {
        $isCreditNote = $invoice->kind === DocumentKind::CreditNote;
        $this->currency = $invoice->currency;
        $this->dom = new DomBuilder([
            '' => $isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE,
            'cac' => self::NS_CAC,
            'cbc' => self::NS_CBC,
        ]);

        $totals = $invoice->totals();
        $root = $this->dom->root($isCreditNote ? 'CreditNote' : 'Invoice');

        $this->dom->add($root, 'cbc:CustomizationID', $invoice->specification->customizationId);
        $this->dom->addOptional($root, 'cbc:ProfileID', $invoice->specification->profileId);
        $this->dom->add($root, 'cbc:ID', $invoice->number);
        $this->dom->add($root, 'cbc:IssueDate', self::date($invoice->issueDate));

        if ($isCreditNote) {
            if ($invoice->dueDate !== null && $invoice->paymentMeans === []) {
                throw new InvalidArgumentException('UBL CreditNote carries the payment due date (BT-9) in PaymentMeans; add at least one payment means.');
            }
            $this->dom->addOptional($root, 'cbc:TaxPointDate', self::optionalDate($invoice->taxPointDate));
            $this->dom->add($root, 'cbc:CreditNoteTypeCode', $invoice->typeCode());
        } else {
            $this->dom->addOptional($root, 'cbc:DueDate', self::optionalDate($invoice->dueDate));
            $this->dom->add($root, 'cbc:InvoiceTypeCode', $invoice->typeCode());
        }

        foreach ($invoice->notes as $note) {
            $this->dom->add($root, 'cbc:Note', $note);
        }

        if (!$isCreditNote) {
            $this->dom->addOptional($root, 'cbc:TaxPointDate', self::optionalDate($invoice->taxPointDate));
        }

        $this->dom->add($root, 'cbc:DocumentCurrencyCode', $invoice->currency);
        $this->dom->addOptional($root, 'cbc:AccountingCost', $invoice->accountingCost);
        $this->dom->addOptional($root, 'cbc:BuyerReference', $invoice->buyerReference);

        if ($invoice->invoicingPeriod !== null) {
            $this->period($root, $invoice->invoicingPeriod);
        }

        if ($invoice->orderReference !== null) {
            $this->dom->add($this->dom->add($root, 'cac:OrderReference'), 'cbc:ID', $invoice->orderReference);
        }

        foreach ($invoice->precedingInvoices as $reference) {
            $documentReference = $this->dom->add($this->dom->add($root, 'cac:BillingReference'), 'cac:InvoiceDocumentReference');
            $this->dom->add($documentReference, 'cbc:ID', $reference->id);
            $this->dom->addOptional($documentReference, 'cbc:IssueDate', self::optionalDate($reference->issueDate));
        }

        if ($invoice->contractReference !== null) {
            $this->dom->add($this->dom->add($root, 'cac:ContractDocumentReference'), 'cbc:ID', $invoice->contractReference);
        }

        $this->party($this->dom->add($root, 'cac:AccountingSupplierParty'), $invoice->seller);
        $this->party($this->dom->add($root, 'cac:AccountingCustomerParty'), $invoice->buyer);

        if ($invoice->payee !== null) {
            $this->payee($root, $invoice->payee);
        }

        if ($invoice->taxRepresentative !== null) {
            $this->taxRepresentative($root, $invoice->taxRepresentative);
        }

        if ($invoice->deliveryDate !== null || $invoice->deliveryAddress !== null) {
            $delivery = $this->dom->add($root, 'cac:Delivery');
            $this->dom->addOptional($delivery, 'cbc:ActualDeliveryDate', self::optionalDate($invoice->deliveryDate));
            if ($invoice->deliveryAddress !== null) {
                $this->address($this->dom->add($delivery, 'cac:DeliveryLocation'), 'cac:Address', $invoice->deliveryAddress);
            }
        }

        foreach ($invoice->paymentMeans as $paymentMeans) {
            $this->paymentMeans($root, $paymentMeans, $isCreditNote ? $invoice->dueDate : null);
        }

        if ($invoice->paymentTerms !== null) {
            $this->dom->add($this->dom->add($root, 'cac:PaymentTerms'), 'cbc:Note', $invoice->paymentTerms);
        }

        foreach ($invoice->allowanceCharges as $allowanceCharge) {
            $this->allowanceCharge($root, $allowanceCharge, true);
        }

        $this->taxTotal($root, $totals);
        $this->monetaryTotal($root, $totals);

        foreach ($invoice->lines as $line) {
            $this->line($root, $line, $isCreditNote);
        }

        return $this->dom->document;
    }

    private function party(DOMElement $parent, Party $party): void
    {
        $element = $this->dom->add($parent, 'cac:Party');

        if ($party->electronicAddress !== null) {
            $this->dom->add($element, 'cbc:EndpointID', $party->electronicAddress->value, ['schemeID' => $party->electronicAddress->scheme]);
        }

        foreach ($party->identifiers as $identifier) {
            $this->dom->add($this->dom->add($element, 'cac:PartyIdentification'), 'cbc:ID', $identifier->value, ['schemeID' => $identifier->scheme]);
        }

        if ($party->tradingName !== null) {
            $this->dom->add($this->dom->add($element, 'cac:PartyName'), 'cbc:Name', $party->tradingName);
        }

        if ($party->address !== null) {
            $this->address($element, 'cac:PostalAddress', $party->address);
        }

        $this->partyTaxSchemes($element, $party);

        $legalEntity = $this->dom->add($element, 'cac:PartyLegalEntity');
        $this->dom->add($legalEntity, 'cbc:RegistrationName', $party->name);
        if ($party->legalRegistrationId !== null) {
            $this->dom->add($legalEntity, 'cbc:CompanyID', $party->legalRegistrationId->value, ['schemeID' => $party->legalRegistrationId->scheme]);
        }
        $this->dom->addOptional($legalEntity, 'cbc:CompanyLegalForm', $party->legalForm);

        if ($party->contact !== null) {
            $contact = $this->dom->add($element, 'cac:Contact');
            $this->dom->addOptional($contact, 'cbc:Name', $party->contact->name);
            $this->dom->addOptional($contact, 'cbc:Telephone', $party->contact->phone);
            $this->dom->addOptional($contact, 'cbc:ElectronicMail', $party->contact->email);
        }
    }

    private function partyTaxSchemes(DOMElement $element, Party $party): void
    {
        if ($party->vatId !== null) {
            $scheme = $this->dom->add($element, 'cac:PartyTaxScheme');
            $this->dom->add($scheme, 'cbc:CompanyID', $party->vatId);
            $this->dom->add($this->dom->add($scheme, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
        }

        if ($party->taxRegistrationId !== null) {
            $scheme = $this->dom->add($element, 'cac:PartyTaxScheme');
            $this->dom->add($scheme, 'cbc:CompanyID', $party->taxRegistrationId);
            $this->dom->add($this->dom->add($scheme, 'cac:TaxScheme'), 'cbc:ID', 'FC');
        }
    }

    private function payee(DOMElement $root, Party $payee): void
    {
        $element = $this->dom->add($root, 'cac:PayeeParty');

        foreach ($payee->identifiers as $identifier) {
            $this->dom->add($this->dom->add($element, 'cac:PartyIdentification'), 'cbc:ID', $identifier->value, ['schemeID' => $identifier->scheme]);
        }

        $this->dom->add($this->dom->add($element, 'cac:PartyName'), 'cbc:Name', $payee->name);

        if ($payee->legalRegistrationId !== null) {
            $this->dom->add(
                $this->dom->add($element, 'cac:PartyLegalEntity'),
                'cbc:CompanyID',
                $payee->legalRegistrationId->value,
                ['schemeID' => $payee->legalRegistrationId->scheme],
            );
        }
    }

    private function taxRepresentative(DOMElement $root, Party $representative): void
    {
        $element = $this->dom->add($root, 'cac:TaxRepresentativeParty');
        $this->dom->add($this->dom->add($element, 'cac:PartyName'), 'cbc:Name', $representative->name);

        if ($representative->address !== null) {
            $this->address($element, 'cac:PostalAddress', $representative->address);
        }

        if ($representative->vatId !== null) {
            $scheme = $this->dom->add($element, 'cac:PartyTaxScheme');
            $this->dom->add($scheme, 'cbc:CompanyID', $representative->vatId);
            $this->dom->add($this->dom->add($scheme, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
        }
    }

    private function address(DOMElement $parent, string $elementName, Address $address): void
    {
        $element = $this->dom->add($parent, $elementName);
        $this->dom->addOptional($element, 'cbc:StreetName', $address->line1);
        $this->dom->addOptional($element, 'cbc:AdditionalStreetName', $address->line2);
        $this->dom->addOptional($element, 'cbc:CityName', $address->city);
        $this->dom->addOptional($element, 'cbc:PostalZone', $address->postalCode);
        $this->dom->addOptional($element, 'cbc:CountrySubentity', $address->countrySubdivision);

        if ($address->line3 !== null && $address->line3 !== '') {
            $this->dom->add($this->dom->add($element, 'cac:AddressLine'), 'cbc:Line', $address->line3);
        }

        $this->dom->add($this->dom->add($element, 'cac:Country'), 'cbc:IdentificationCode', $address->countryCode);
    }

    private function paymentMeans(DOMElement $root, PaymentMeans $paymentMeans, ?DateTimeImmutable $creditNoteDueDate): void
    {
        $element = $this->dom->add($root, 'cac:PaymentMeans');
        $this->dom->add($element, 'cbc:PaymentMeansCode', $paymentMeans->typeCode, ['name' => $paymentMeans->typeText]);
        $this->dom->addOptional($element, 'cbc:PaymentDueDate', self::optionalDate($creditNoteDueDate));
        $this->dom->addOptional($element, 'cbc:PaymentID', $paymentMeans->remittanceInformation);

        if ($paymentMeans->account !== null) {
            $account = $this->dom->add($element, 'cac:PayeeFinancialAccount');
            $this->dom->add($account, 'cbc:ID', $paymentMeans->account->id);
            $this->dom->addOptional($account, 'cbc:Name', $paymentMeans->account->name);
            if ($paymentMeans->account->bic !== null) {
                $this->dom->add($this->dom->add($account, 'cac:FinancialInstitutionBranch'), 'cbc:ID', $paymentMeans->account->bic);
            }
        }
    }

    private function allowanceCharge(DOMElement $parent, AllowanceCharge $allowanceCharge, bool $documentLevel): void
    {
        $element = $this->dom->add($parent, 'cac:AllowanceCharge');
        $this->dom->add($element, 'cbc:ChargeIndicator', $allowanceCharge->isCharge ? 'true' : 'false');
        $this->dom->addOptional($element, 'cbc:AllowanceChargeReasonCode', $allowanceCharge->reasonCode);
        $this->dom->addOptional($element, 'cbc:AllowanceChargeReason', $allowanceCharge->reason);

        if ($allowanceCharge->percentage !== null) {
            $this->dom->add($element, 'cbc:MultiplierFactorNumeric', Format::number($allowanceCharge->percentage));
        }

        $this->dom->add($element, 'cbc:Amount', Format::amount($allowanceCharge->amount), ['currencyID' => $this->currency]);

        if ($allowanceCharge->baseAmount !== null) {
            $this->dom->add($element, 'cbc:BaseAmount', Format::amount($allowanceCharge->baseAmount), ['currencyID' => $this->currency]);
        }

        if ($documentLevel && $allowanceCharge->taxCategory !== null) {
            $this->taxCategory($element, 'cac:TaxCategory', $allowanceCharge->taxCategory);
        }
    }

    private function taxCategory(DOMElement $parent, string $elementName, TaxCategory $taxCategory): void
    {
        $element = $this->dom->add($parent, $elementName);
        $this->dom->add($element, 'cbc:ID', $taxCategory->category->value);

        if ($taxCategory->rate !== null) {
            $this->dom->add($element, 'cbc:Percent', Format::percent($taxCategory->rate));
        }

        $this->dom->add($this->dom->add($element, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
    }

    private function taxTotal(DOMElement $root, Totals $totals): void
    {
        $taxTotal = $this->dom->add($root, 'cac:TaxTotal');
        $this->dom->add($taxTotal, 'cbc:TaxAmount', Format::amount($totals->vatTotal), ['currencyID' => $this->currency]);

        foreach ($totals->vatBreakdown as $breakdown) {
            $this->taxSubtotal($taxTotal, $breakdown);
        }
    }

    private function taxSubtotal(DOMElement $taxTotal, VatBreakdown $breakdown): void
    {
        $subtotal = $this->dom->add($taxTotal, 'cac:TaxSubtotal');
        $this->dom->add($subtotal, 'cbc:TaxableAmount', Format::amount($breakdown->taxableAmount), ['currencyID' => $this->currency]);
        $this->dom->add($subtotal, 'cbc:TaxAmount', Format::amount($breakdown->taxAmount), ['currencyID' => $this->currency]);

        $category = $this->dom->add($subtotal, 'cac:TaxCategory');
        $this->dom->add($category, 'cbc:ID', $breakdown->category->value);
        if ($breakdown->rate !== null) {
            $this->dom->add($category, 'cbc:Percent', Format::percent($breakdown->rate));
        }
        $this->dom->addOptional($category, 'cbc:TaxExemptionReasonCode', $breakdown->exemptionReasonCode);
        $this->dom->addOptional($category, 'cbc:TaxExemptionReason', $breakdown->exemptionReason);
        $this->dom->add($this->dom->add($category, 'cac:TaxScheme'), 'cbc:ID', 'VAT');
    }

    private function monetaryTotal(DOMElement $root, Totals $totals): void
    {
        $element = $this->dom->add($root, 'cac:LegalMonetaryTotal');
        $currency = ['currencyID' => $this->currency];

        $this->dom->add($element, 'cbc:LineExtensionAmount', Format::amount($totals->lineNetTotal), $currency);
        $this->dom->add($element, 'cbc:TaxExclusiveAmount', Format::amount($totals->taxExclusiveAmount), $currency);
        $this->dom->add($element, 'cbc:TaxInclusiveAmount', Format::amount($totals->taxInclusiveAmount), $currency);

        if (!$totals->allowanceTotal->isZero()) {
            $this->dom->add($element, 'cbc:AllowanceTotalAmount', Format::amount($totals->allowanceTotal), $currency);
        }
        if (!$totals->chargeTotal->isZero()) {
            $this->dom->add($element, 'cbc:ChargeTotalAmount', Format::amount($totals->chargeTotal), $currency);
        }
        if (!$totals->prepaidAmount->isZero()) {
            $this->dom->add($element, 'cbc:PrepaidAmount', Format::amount($totals->prepaidAmount), $currency);
        }
        if ($totals->roundingAmount !== null) {
            $this->dom->add($element, 'cbc:PayableRoundingAmount', Format::amount($totals->roundingAmount), $currency);
        }

        $this->dom->add($element, 'cbc:PayableAmount', Format::amount($totals->payableAmount), $currency);
    }

    private function line(DOMElement $root, Line $line, bool $isCreditNote): void
    {
        $element = $this->dom->add($root, $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine');
        $this->dom->add($element, 'cbc:ID', $line->id);
        $this->dom->addOptional($element, 'cbc:Note', $line->note);
        $this->dom->add(
            $element,
            $isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity',
            Format::number($line->quantity),
            ['unitCode' => $line->unitCode],
        );
        $this->dom->add($element, 'cbc:LineExtensionAmount', Format::amount($line->netAmount()), ['currencyID' => $this->currency]);

        if ($line->period !== null) {
            $this->period($element, $line->period);
        }

        if ($line->orderLineReference !== null) {
            $this->dom->add($this->dom->add($element, 'cac:OrderLineReference'), 'cbc:LineID', $line->orderLineReference);
        }

        foreach ($line->allowanceCharges as $allowanceCharge) {
            $this->allowanceCharge($element, $allowanceCharge, false);
        }

        $item = $this->dom->add($element, 'cac:Item');
        $this->dom->addOptional($item, 'cbc:Description', $line->description);
        $this->dom->add($item, 'cbc:Name', $line->itemName);
        if ($line->buyersItemId !== null) {
            $this->dom->add($this->dom->add($item, 'cac:BuyersItemIdentification'), 'cbc:ID', $line->buyersItemId);
        }
        if ($line->sellersItemId !== null) {
            $this->dom->add($this->dom->add($item, 'cac:SellersItemIdentification'), 'cbc:ID', $line->sellersItemId);
        }
        if ($line->standardItemId !== null) {
            $this->dom->add(
                $this->dom->add($item, 'cac:StandardItemIdentification'),
                'cbc:ID',
                $line->standardItemId->value,
                ['schemeID' => $line->standardItemId->scheme],
            );
        }
        $this->taxCategory($item, 'cac:ClassifiedTaxCategory', $line->taxCategory);

        $price = $this->dom->add($element, 'cac:Price');
        $this->dom->add($price, 'cbc:PriceAmount', Format::price($line->netPrice), ['currencyID' => $this->currency]);
        if ($line->baseQuantity !== null) {
            $this->dom->add($price, 'cbc:BaseQuantity', Format::number($line->baseQuantity), ['unitCode' => $line->unitCode]);
        }
        $discount = $line->priceDiscount();
        if ($line->grossPrice !== null && $discount !== null) {
            $priceAllowance = $this->dom->add($price, 'cac:AllowanceCharge');
            $this->dom->add($priceAllowance, 'cbc:ChargeIndicator', 'false');
            $this->dom->add($priceAllowance, 'cbc:Amount', Format::price($discount), ['currencyID' => $this->currency]);
            $this->dom->add($priceAllowance, 'cbc:BaseAmount', Format::price($line->grossPrice), ['currencyID' => $this->currency]);
        }
    }

    private function period(DOMElement $parent, Period $period): void
    {
        $element = $this->dom->add($parent, 'cac:InvoicePeriod');
        $this->dom->addOptional($element, 'cbc:StartDate', self::optionalDate($period->start));
        $this->dom->addOptional($element, 'cbc:EndDate', self::optionalDate($period->end));
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    private static function optionalDate(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }
}
