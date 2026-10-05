<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Support;

use DateTimeImmutable;
use Pnscripts\Invoice\Model\Address;
use Pnscripts\Invoice\Model\AllowanceCharge;
use Pnscripts\Invoice\Model\Contact;
use Pnscripts\Invoice\Model\DocumentKind;
use Pnscripts\Invoice\Model\DocumentReference;
use Pnscripts\Invoice\Model\Identifier;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Line;
use Pnscripts\Invoice\Model\Party;
use Pnscripts\Invoice\Model\PaymentMeans;
use Pnscripts\Invoice\Model\Period;
use Pnscripts\Invoice\Model\Specification;
use Pnscripts\Invoice\Model\TaxCategory;

/**
 * Fictitious invoices used by the tests and golden files. All names, numbers and
 * identifiers are made up.
 */
final class SampleInvoices
{
    public static function seller(): Party
    {
        return new Party(
            name: 'Example Supplier Ltd',
            address: new Address('BG', 'Example Street 1', null, null, 'Sofia', '1000'),
            vatId: 'BG000000000',
            legalRegistrationId: new Identifier('000000000'),
            electronicAddress: new Identifier('seller@example.com', 'EM'),
            contact: new Contact('Accounts', '+359 2 000 0000', 'accounts@example.com'),
        );
    }

    public static function buyer(string $country = 'DE', ?string $vatId = 'DE000000000'): Party
    {
        return new Party(
            name: 'Example Buyer GmbH',
            address: new Address($country, 'Musterstrasse 2', 'Building B', null, 'Berlin', '10115'),
            vatId: $vatId,
            electronicAddress: new Identifier('buyer@example.com', 'EM'),
        );
    }

    /**
     * Domestic B2B invoice with two standard rates, a zero-rated line, a line allowance,
     * a document level allowance and charge, and a prepaid amount.
     */
    public static function standard(Specification $specification = new Specification()): Invoice
    {
        $standard = TaxCategory::standard('20');

        return new Invoice(
            number: 'INV-2026-0001',
            issueDate: new DateTimeImmutable('2026-10-01'),
            currency: 'EUR',
            seller: self::seller(),
            buyer: self::buyer('BG', 'BG111111111'),
            lines: [
                new Line('1', 'Consulting', '10', 'HUR', '85.50', $standard, sellersItemId: 'CONS-1', period: new Period(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'))),
                new Line('2', 'Printed manual', '3', 'C62', '12.3456', TaxCategory::standard('9'), grossPrice: '15', standardItemId: new Identifier('4000862141404', '0160')),
                new Line(
                    '3',
                    'Licence',
                    '1',
                    'C62',
                    '400',
                    $standard,
                    allowanceCharges: [AllowanceCharge::allowance('40', 'Loyalty discount', '95')],
                    description: 'Annual licence',
                ),
                new Line('4', 'Exported support pack', '2', 'C62', '25', TaxCategory::zeroRated(), baseQuantity: '1'),
            ],
            specification: $specification,
            dueDate: new DateTimeImmutable('2026-10-31'),
            buyerReference: 'PO-778',
            orderReference: 'PO-778',
            notes: ['Thank you for your business.'],
            invoicingPeriod: new Period(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')),
            paymentMeans: [PaymentMeans::creditTransfer('BG80BNBG96611020345678', 'Example Supplier Ltd', 'BNBGBGSD', 'INV-2026-0001')],
            paymentTerms: 'Net 30 days',
            allowanceCharges: [
                AllowanceCharge::allowance('10.005', 'Early order discount', null, $standard),
                AllowanceCharge::charge('15', 'Freight', 'FC', $standard),
            ],
            prepaidAmount: '100',
        );
    }

    /**
     * Intra-EU reverse charge invoice (category AE).
     */
    public static function reverseCharge(): Invoice
    {
        return new Invoice(
            number: 'INV-2026-0002',
            issueDate: new DateTimeImmutable('2026-10-02'),
            currency: 'EUR',
            seller: self::seller(),
            buyer: self::buyer(),
            lines: [new Line('1', 'Software development', '20', 'HUR', '60', TaxCategory::reverseCharge())],
            dueDate: new DateTimeImmutable('2026-11-01'),
            paymentMeans: [PaymentMeans::creditTransfer('BG80BNBG96611020345678')],
        );
    }

    /**
     * VAT exempt invoice (category E) with a document level charge.
     */
    public static function exempt(): Invoice
    {
        $exempt = TaxCategory::exempt('Exempt under the national VAT act', 'VATEX-EU-132');

        return new Invoice(
            number: 'INV-2026-0003',
            issueDate: new DateTimeImmutable('2026-10-03'),
            currency: 'BGN',
            seller: self::seller(),
            buyer: self::buyer('BG', null),
            lines: [new Line('1', 'Training course', '1', 'C62', '500', $exempt)],
            paymentTerms: 'Payable on receipt',
            allowanceCharges: [AllowanceCharge::charge('20', 'Materials', null, $exempt)],
        );
    }

    /**
     * Credit note referencing a preceding invoice.
     */
    public static function creditNote(): Invoice
    {
        return new Invoice(
            number: 'CN-2026-0001',
            issueDate: new DateTimeImmutable('2026-10-04'),
            currency: 'EUR',
            seller: self::seller(),
            buyer: self::buyer('BG', 'BG111111111'),
            lines: [new Line('1', 'Consulting (returned hours)', '2', 'HUR', '85.50', TaxCategory::standard('20'))],
            kind: DocumentKind::CreditNote,
            dueDate: new DateTimeImmutable('2026-10-14'),
            precedingInvoices: [new DocumentReference('INV-2026-0001', new DateTimeImmutable('2026-10-01'))],
            paymentMeans: [PaymentMeans::creditTransfer('BG80BNBG96611020345678')],
        );
    }
}
