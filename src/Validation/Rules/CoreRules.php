<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Rules;

use PnScripts\Invoice\Validation\View\AllowanceChargeView;
use PnScripts\Invoice\Validation\View\InvoiceView;
use PnScripts\Invoice\Validation\View\PartyView;
use PnScripts\Invoice\Validation\View\PeriodView;

/**
 * EN 16931 core business rules (BR-nn): presence of mandatory business terms and simple value checks.
 */
final class CoreRules implements RuleSet
{
    public function rules(): array
    {
        return [
            'BR-01' => 'Specification identifier (BT-24) is missing.',
            'BR-02' => 'Invoice number (BT-1) is missing.',
            'BR-03' => 'Invoice issue date (BT-2) is missing.',
            'BR-04' => 'Invoice type code (BT-3) is missing.',
            'BR-05' => 'Invoice currency code (BT-5) is missing.',
            'BR-06' => 'Seller name (BT-27) is missing.',
            'BR-07' => 'Buyer name (BT-44) is missing.',
            'BR-08' => 'Seller postal address (BG-5) is missing.',
            'BR-09' => 'Seller country code (BT-40) is missing in the seller postal address.',
            'BR-10' => 'Buyer postal address (BG-8) is missing.',
            'BR-11' => 'Buyer country code (BT-55) is missing in the buyer postal address.',
            'BR-12' => 'Sum of invoice line net amounts (BT-106) is missing.',
            'BR-13' => 'Invoice total amount without VAT (BT-109) is missing.',
            'BR-14' => 'Invoice total amount with VAT (BT-112) is missing.',
            'BR-15' => 'Amount due for payment (BT-115) is missing.',
            'BR-16' => 'The invoice has no invoice line (BG-25).',
            'BR-21' => 'Invoice line identifier (BT-126) is missing.',
            'BR-22' => 'Invoiced quantity (BT-129) is missing.',
            'BR-23' => 'Invoiced quantity unit of measure code (BT-130) is missing.',
            'BR-24' => 'Invoice line net amount (BT-131) is missing.',
            'BR-25' => 'Item name (BT-153) is missing.',
            'BR-26' => 'Item net price (BT-146) is missing.',
            'BR-27' => 'Item net price (BT-146) must not be negative.',
            'BR-28' => 'Item gross price (BT-148) must not be negative.',
            'BR-29' => 'Invoicing period end date (BT-74) is before the start date (BT-73).',
            'BR-30' => 'Invoice line period end date (BT-135) is before the start date (BT-134).',
            'BR-31' => 'Document level allowance amount (BT-92) is missing.',
            'BR-32' => 'Document level allowance VAT category code (BT-95) is missing.',
            'BR-33' => 'Document level allowance needs a reason (BT-97) or reason code (BT-98).',
            'BR-36' => 'Document level charge amount (BT-99) is missing.',
            'BR-37' => 'Document level charge VAT category code (BT-102) is missing.',
            'BR-38' => 'Document level charge needs a reason (BT-104) or reason code (BT-105).',
            'BR-41' => 'Invoice line allowance amount (BT-136) is missing.',
            'BR-42' => 'Invoice line allowance needs a reason (BT-139) or reason code (BT-140).',
            'BR-43' => 'Invoice line charge amount (BT-141) is missing.',
            'BR-44' => 'Invoice line charge needs a reason (BT-144) or reason code (BT-145).',
            'BR-45' => 'VAT category taxable amount (BT-116) is missing in a VAT breakdown.',
            'BR-46' => 'VAT category tax amount (BT-117) is missing in a VAT breakdown.',
            'BR-47' => 'VAT category code (BT-118) is missing in a VAT breakdown.',
            'BR-48' => 'VAT category rate (BT-119) is missing in a VAT breakdown (only category "O" may omit it).',
            'BR-49' => 'Payment means type code (BT-81) is missing.',
            'BR-50' => 'Payment account identifier (BT-84) is missing in the credit transfer information.',
            'BR-55' => 'Preceding invoice reference (BT-25) is missing.',
            'BR-61' => 'Payment account identifier (BT-84) is required for credit transfer payment means (codes 30 and 58).',
            'BR-62' => 'Seller electronic address (BT-34) needs a scheme identifier.',
            'BR-63' => 'Buyer electronic address (BT-49) needs a scheme identifier.',
        ];
    }

    public function check(InvoiceView $view, Report $report): void
    {
        $root = $view->rootPath();

        $report->assert($view->specificationIdentifier()->filled(), 'BR-01', $root);
        $report->assert($view->invoiceNumber()->filled(), 'BR-02', $root);
        $report->assert($view->issueDate()->filled(), 'BR-03', $root);
        $report->assert($view->typeCode()->filled(), 'BR-04', $root);
        $report->assert($view->currencyCode()->filled(), 'BR-05', $root);

        $this->parties($view, $report);
        $this->totals($view, $report);

        $lines = $view->lines();
        $report->assert($lines !== [], 'BR-16', $root);

        foreach ($lines as $line) {
            $report->assert($line->id->filled(), 'BR-21', $line->path);
            $report->assert($line->quantity->exists(), 'BR-22', $line->path);
            $report->assert($line->unitCode->exists(), 'BR-23', $line->path);
            $report->assert($line->netAmount->exists(), 'BR-24', $line->path);
            $report->assert($line->itemName->filled(), 'BR-25', $line->path);
            $report->assert($line->netPrice->exists(), 'BR-26', $line->path);

            $netPrice = $line->netPrice->decimal();
            $report->assert($netPrice !== null && !$netPrice->isNegative(), 'BR-27', $line->netPrice->path);

            $grossPrice = $line->grossPrice->decimal();
            $report->assert(!$line->grossPrice->exists() || ($grossPrice !== null && !$grossPrice->isNegative()), 'BR-28', $line->grossPrice->path);

            if ($line->period !== null) {
                $report->assert($this->periodIsOrdered($line->period), 'BR-30', $line->period->path);
            }

            foreach ($line->allowanceCharges as $allowanceCharge) {
                $this->lineAllowanceCharge($allowanceCharge, $report);
            }
        }

        $period = $view->invoicingPeriod();
        if ($period !== null) {
            $report->assert($this->periodIsOrdered($period), 'BR-29', $period->path);
        }

        foreach ($view->documentAllowanceCharges() as $allowanceCharge) {
            $hasReason = $allowanceCharge->reason->exists() || $allowanceCharge->reasonCode->exists();
            if ($allowanceCharge->isCharge) {
                $report->assert($allowanceCharge->amount->exists(), 'BR-36', $allowanceCharge->path);
                $report->assert($allowanceCharge->vatCategory->exists(), 'BR-37', $allowanceCharge->path);
                $report->assert($hasReason, 'BR-38', $allowanceCharge->path);
            } else {
                $report->assert($allowanceCharge->amount->exists(), 'BR-31', $allowanceCharge->path);
                $report->assert($allowanceCharge->vatCategory->exists(), 'BR-32', $allowanceCharge->path);
                $report->assert($hasReason, 'BR-33', $allowanceCharge->path);
            }
        }

        foreach ($view->vatBreakdowns() as $breakdown) {
            $report->assert($breakdown->taxableAmount->exists(), 'BR-45', $breakdown->path);
            $report->assert($breakdown->taxAmount->exists(), 'BR-46', $breakdown->path);
            $report->assert($breakdown->category->exists(), 'BR-47', $breakdown->path);
            $report->assert($breakdown->rate->exists() || $breakdown->category->normalized() === 'O', 'BR-48', $breakdown->path);
        }

        foreach ($view->paymentInstructions() as $instruction) {
            $report->assert($instruction->typeCode->exists(), 'BR-49', $instruction->path);

            if ($instruction->isCreditTransfer()) {
                foreach ($instruction->creditTransferAccounts as $account) {
                    $report->assert($account->filled(), 'BR-50', $account->path);
                }

                $hasAccount = false;
                foreach ($instruction->creditTransferAccounts as $account) {
                    $hasAccount = $hasAccount || $account->exists();
                }
                $report->assert($hasAccount, 'BR-61', $instruction->path);
            }
        }

        foreach ($view->precedingInvoiceReferences() as $reference) {
            $report->assert($reference->exists(), 'BR-55', $reference->path);
        }
    }

    private function parties(InvoiceView $view, Report $report): void
    {
        $root = $view->rootPath();
        $seller = $view->seller();
        $buyer = $view->buyer();

        $report->assert($seller !== null && $seller->name->filled(), 'BR-06', $seller->path ?? $root);
        $report->assert($buyer !== null && $buyer->name->filled(), 'BR-07', $buyer->path ?? $root);

        $report->assert($seller !== null && $seller->postalAddressPath !== null, 'BR-08', $seller->path ?? $root);
        if ($seller !== null && $seller->postalAddressPath !== null) {
            $report->assert($seller->countryCode->filled(), 'BR-09', $seller->postalAddressPath);
        }

        $report->assert($buyer !== null && $buyer->postalAddressPath !== null, 'BR-10', $buyer->path ?? $root);
        if ($buyer !== null && $buyer->postalAddressPath !== null) {
            $report->assert($buyer->countryCode->filled(), 'BR-11', $buyer->postalAddressPath);
        }

        $this->electronicAddress($seller, 'BR-62', $report);
        $this->electronicAddress($buyer, 'BR-63', $report);
    }

    private function electronicAddress(?PartyView $party, string $ruleId, Report $report): void
    {
        if ($party !== null && $party->electronicAddress->exists()) {
            $report->assert($party->electronicAddressScheme->exists(), $ruleId, $party->electronicAddress->path);
        }
    }

    private function totals(InvoiceView $view, Report $report): void
    {
        $totals = $view->totals();
        if ($totals === null) {
            // The XML schema already requires the totals group; the official rules use it as context.
            return;
        }

        $report->assert($totals->lineNetTotal->exists(), 'BR-12', $totals->path);
        $report->assert($totals->taxExclusiveAmount->exists(), 'BR-13', $totals->path);
        $report->assert($totals->taxInclusiveAmount->exists(), 'BR-14', $totals->path);
        $report->assert($totals->payableAmount->exists(), 'BR-15', $totals->path);
    }

    private function lineAllowanceCharge(AllowanceChargeView $allowanceCharge, Report $report): void
    {
        $hasReason = $allowanceCharge->reason->exists() || $allowanceCharge->reasonCode->exists();

        if ($allowanceCharge->isCharge) {
            $report->assert($allowanceCharge->amount->exists(), 'BR-43', $allowanceCharge->path);
            $report->assert($hasReason, 'BR-44', $allowanceCharge->path);

            return;
        }

        $report->assert($allowanceCharge->amount->exists(), 'BR-41', $allowanceCharge->path);
        $report->assert($hasReason, 'BR-42', $allowanceCharge->path);
    }

    private function periodIsOrdered(PeriodView $period): bool
    {
        if (!$period->start->filled() || !$period->end->filled()) {
            return true;
        }

        $start = str_replace('-', '', $period->start->normalized());
        $end = str_replace('-', '', $period->end->normalized());

        return strcmp($end, $start) >= 0;
    }
}
