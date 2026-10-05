<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

use Pnscripts\Invoice\Validation\Syntax;

/**
 * Syntax-neutral read access to the EN 16931 business terms of a UBL or CII document.
 *
 * Business rules are written once against this interface; {@see UblView} and {@see CiiView}
 * provide the syntax bindings. Accessors never throw on incomplete documents: missing
 * elements are reported as absent {@see Field}s.
 */
interface InvoiceView
{
    public function syntax(): Syntax;

    /** XPath of the document root, used as location for document-level rules. */
    public function rootPath(): string;

    public function specificationIdentifier(): Field;

    public function invoiceNumber(): Field;

    public function issueDate(): Field;

    public function typeCode(): Field;

    public function currencyCode(): Field;

    /** Invoice total VAT amount (BT-110) in the document currency. */
    public function vatTotal(): Field;

    /** Number of invoice total VAT amounts given in the document currency (BR-CO-15 requires exactly one). */
    public function vatTotalCount(): int;

    public function seller(): ?PartyView;

    public function buyer(): ?PartyView;

    public function taxRepresentative(): ?PartyView;

    public function totals(): ?TotalsView;

    /** @return list<LineView> */
    public function lines(): array;

    /** @return list<AllowanceChargeView> document level allowances and charges */
    public function documentAllowanceCharges(): array;

    /** @return list<VatBreakdownView> */
    public function vatBreakdowns(): array;

    /** @return list<PaymentInstructionView> */
    public function paymentInstructions(): array;

    public function invoicingPeriod(): ?PeriodView;

    /** @return list<Field> preceding invoice references (BT-25), one per BG-3 */
    public function precedingInvoiceReferences(): array;
}
