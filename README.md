# PN Invoice

Free, MIT-licensed, framework-agnostic PHP library by **PN Scripts** to **generate and validate EN 16931 e-invoices** in both syntaxes allowed by the European standard:

- **OASIS UBL 2.1** `Invoice` and `CreditNote`
- **UN/CEFACT Cross Industry Invoice (CII) D16B**

It gives you a small, typed invoice model, writers for both syntaxes, and a layered validator (XML Schema + a documented set of EN 16931 business rules in pure PHP + an optional hook for a full Schematron validator). No framework, no HTTP calls, no floats.

> **Disclaimer.** PN Invoice is a technical tool. It is **not legal or tax advice**, and passing its checks does **not** certify that an invoice is legally compliant. VAT treatment, exemption reasons, numbering and archiving obligations depend on your country and your business; generated invoices must be checked by you or your accountant before they are sent. The PHP business rules cover a **subset** of EN 16931 (listed below); for full conformance testing use the official Schematron through the [adapter](#full-schematron-validation-optional).

## Requirements

- PHP 8.3, 8.4 or 8.5
- Extensions: `dom`, `libxml`, `bcmath`

## Installation

```bash
composer require pnscripts/pn-invoice
```

The official UBL 2.1 and CII D16B XML Schemas are bundled in `resources/schemas/` (their licences allow verbatim redistribution, see [NOTICE](NOTICE)), so XSD validation works offline right after installation.

## Quick start

### Create an invoice

```php
use PnScripts\Invoice\Model\{Address, AllowanceCharge, Identifier, Invoice, Line, Party, PaymentMeans, Specification, TaxCategory};

$seller = new Party(
    name: 'Example Supplier Ltd',
    address: new Address(countryCode: 'BG', line1: 'Example Street 1', city: 'Sofia', postalCode: '1000'),
    vatId: 'BG000000000',
    electronicAddress: new Identifier('seller@example.com', 'EM'),
);

$buyer = new Party(
    name: 'Example Buyer GmbH',
    address: new Address(countryCode: 'DE', line1: 'Musterstrasse 2', city: 'Berlin', postalCode: '10115'),
    vatId: 'DE000000000',
);

$vat20 = TaxCategory::standard('20');   // the rate is your data; nothing is hard-coded

$invoice = new Invoice(
    number: 'INV-2026-0001',
    issueDate: new DateTimeImmutable('2026-10-01'),
    currency: 'EUR',
    seller: $seller,
    buyer: $buyer,
    lines: [
        new Line(id: '1', itemName: 'Consulting', quantity: '10', unitCode: 'HUR', netPrice: '85.50', taxCategory: $vat20),
        new Line(id: '2', itemName: 'Licence', quantity: '1', unitCode: 'C62', netPrice: '400', taxCategory: $vat20),
    ],
    specification: Specification::en16931(),           // or Specification::peppolBis3(), or new Specification('urn:...')
    dueDate: new DateTimeImmutable('2026-10-31'),
    buyerReference: 'PO-778',
    paymentMeans: [PaymentMeans::creditTransfer('BG80BNBG96611020345678', 'Example Supplier Ltd')],
    allowanceCharges: [AllowanceCharge::charge('15', 'Freight', 'FC', $vat20)],
);

$totals = $invoice->totals();            // line total, VAT breakdown, payable amount (bcmath, rounded per EN 16931)
echo $totals->payableAmount->toFixed(2); // "1524.00"
```

Amounts are passed as strings or integers and kept as exact decimals (`PnScripts\Invoice\Decimal`, backed by bcmath). Totals and the VAT breakdown are always **derived** from lines and allowances/charges, so the calculation rules BR-CO-10 to BR-CO-17 hold by construction.

### Write UBL or CII

```php
use PnScripts\Invoice\Writer\{CiiWriter, UblWriter};

$ublXml = (new UblWriter())->write($invoice);   // <Invoice> or <CreditNote> depending on $invoice->kind
$ciiXml = (new CiiWriter())->write($invoice);   // <rsm:CrossIndustryInvoice>
```

A credit note is the same model with `kind: DocumentKind::CreditNote` (type code 381 by default) and usually a `precedingInvoices` reference.

### Validate

```php
use PnScripts\Invoice\Validation\InvoiceValidator;

$result = (new InvoiceValidator())->validateXml($ublXml);   // or validateFile($path), validateInvoice($invoice)

if (!$result->isValid()) {
    foreach ($result->errors() as $violation) {
        printf("%s [%s] %s at %s\n", $violation->ruleId, $violation->layer->value, $violation->message, $violation->location);
    }
}
```

Each `Violation` has a rule id (`BR-CO-10`, `XSD`, `XML`, ...), a severity (`error`, `warning`, `info`), the layer that produced it, a message, an XPath location and, for XML/XSD errors, a line number. `$result->toArray()` gives a JSON-ready structure.

### Command line

```bash
vendor/bin/pn-invoice validate invoice.xml            # text report
vendor/bin/pn-invoice validate a.xml b.xml --json     # JSON report
vendor/bin/pn-invoice validate invoice.xml --no-xsd --only=BR-CO-10,BR-CO-15
vendor/bin/pn-invoice rules                           # list every implemented business rule
```

Exit codes: `0` valid, `1` validation errors, `2` usage or input error.

## How validation works

| Layer | What it checks | Notes |
|-------|----------------|-------|
| XML | Well-formedness, known root element | No network access, DOCTYPE/external entities refused |
| XSD | Official UBL 2.1 / CII D16B schema | Structure, element order, data types |
| Business rules | 104 EN 16931 rules in pure PHP (table below) | Same results as the official artefacts on their own unit tests |
| Schematron (optional) | Anything your external validator checks | You plug it in; see below |

### Implemented business rules (v0.1)

| Group | Implemented | Not implemented yet |
|-------|-------------|---------------------|
| Core (BR-nn) | BR-01 to BR-16, BR-21 to BR-33, BR-36 to BR-38, BR-41 to BR-50, BR-55, BR-61, BR-62, BR-63 | BR-17 to BR-20 (payee, tax representative), BR-51 to BR-54, BR-56, BR-57, BR-64, BR-65 |
| Conditions and calculations (BR-CO) | BR-CO-04, BR-CO-09 to BR-CO-24, BR-CO-26 | BR-CO-03; BR-CO-05 to BR-CO-08 (always true in the official artefacts); BR-CO-25 (not in the EN 16931 artefacts 1.3.16) |
| Standard rated (BR-S) | BR-S-01 to BR-S-10 | |
| Zero rated (BR-Z) | BR-Z-01 to BR-Z-10 | |
| Exempt (BR-E) | BR-E-01 to BR-E-10 | |
| Reverse charge (BR-AE) | BR-AE-01 to BR-AE-10 | |
| Intra-community (BR-IC / K), export (BR-G), not subject to VAT (BR-O), Canary Islands (BR-IG / L), Ceuta and Melilla (BR-IP / M) | none | planned |
| Code lists (BR-CL), decimals (BR-DEC), syntax rules (UBL-CR/SR/DT, CII-SR/DT) | none | XSD covers structure only |

The writers can produce all EN 16931 VAT categories (S, Z, E, AE, K, G, O, L, M); only the rule checks for K, G, O, L and M are missing.

**How faithfully are the rules implemented?** The rules are written once against a syntax-neutral view of the document and bound to UBL and CII with XPath. Their semantics mirror the official EN 16931 validation artefacts v1.3.16, including their tolerances: BR-CO-17, BR-S-08 and BR-S-09 accept a deviation of less than 1 currency unit, exactly like the official Schematron (the CII binding of BR-CO-17 uses an inclusive bound). The test suite runs all **681 official unit test cases** (`test/` folder of [ConnectingEurope/eInvoicing-EN16931](https://github.com/ConnectingEurope/eInvoicing-EN16931)) that name an implemented rule and checks every expected outcome, and validates all 34 official example invoices.

Run `vendor/bin/pn-invoice rules` for the full list with descriptions.

### Full Schematron validation (optional)

The official EN 16931 Schematron, Peppol BIS and XRechnung rules need an **XSLT 2.0** processor. PHP's `ext-xsl` only supports XSLT 1.0, so this library does not run them itself. Instead it offers the `SchematronValidator` interface and an SVRL parser. A ready-made adapter runs any command that prints SVRL (Schematron Validation Report Language), without a shell:

```php
use PnScripts\Invoice\Validation\InvoiceValidator;
use PnScripts\Invoice\Validation\Schematron\CommandSchematronValidator;
use PnScripts\Invoice\Validation\Syntax;

$schematron = new CommandSchematronValidator([
    Syntax::UblInvoice->value => ['java', '-jar', '/opt/saxon/saxon-he.jar', '-s:{file}', '-xsl:/opt/en16931/ubl/xslt/EN16931-UBL-validation.xslt'],
    Syntax::UblCreditNote->value => ['java', '-jar', '/opt/saxon/saxon-he.jar', '-s:{file}', '-xsl:/opt/en16931/ubl/xslt/EN16931-UBL-validation.xslt'],
    Syntax::Cii->value => ['java', '-jar', '/opt/saxon/saxon-he.jar', '-s:{file}', '-xsl:/opt/en16931/cii/xslt/EN16931-CII-validation.xslt'],
]);

$result = (new InvoiceValidator(schematron: $schematron))->validateFile('invoice.xml');
```

Saxon and the XSLT files are not shipped; download them from their official sources. You can also implement `SchematronValidator` yourself (for example to call a validation service).

## What is not covered (yet)

- **No transport.** No Peppol access point, AS4, e-mail sending or government portal upload.
- **No country CIUS or extension profiles yet**: XRechnung, Factur-X / ZUGFeRD (PDF/A-3 embedding), Peppol BIS specific rules (PEPPOL-EN16931-*), Bulgarian, Polish KSeF and Romanian e-Factura formats. These are planned for the commercial **PN Invoice Pro** package. Setting `Specification::peppolBis3()` only writes the Peppol identifiers; it does not apply the Peppol rules.
- **No XML reader** that turns UBL/CII back into the model (validation reads XML directly).
- The model covers the EN 16931 core used in typical B2B invoices and credit notes. Not modelled yet: payee financial details beyond credit transfer, card payments, direct debit mandates, attachments (BG-24), item attributes (BG-32), item classification, delivery party name, VAT accounting currency (BT-6/BT-111), tax point date code (BT-8), project/receiving/despatch references.
- Not a certified or accredited validator.

## Development

```bash
composer install
composer check          # php-cs-fixer (dry run) + PHPStan level 8 + PHPUnit
PN_INVOICE_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter GoldenFileTest   # after an intentional writer change
```

The official EN 16931 test files in `tests/fixtures/official/` are EUPL-1.2 licensed, kept separate with their own [NOTICE](tests/fixtures/official/NOTICE), and are not part of the Composer package.

## Support and more from PN Scripts

- Issues and questions: [GitHub issues](https://github.com/pnscripts/pn-invoice/issues). Security reports: see [SECURITY.md](SECURITY.md).
- Need e-invoicing built into a Laravel or Filament app, or an upgrade to Laravel 13 / Filament 5? See [Laravel and Filament upgrades and care](https://pnscripts.com/services/laravel-filament-care).
- [PN Shop](https://github.com/pnscripts/pn-shop): open-source Laravel e-commerce platform. All products: [pnscripts.com/products](https://pnscripts.com/products)

## Licence

PN Invoice is released under the [MIT License](LICENSE), copyright ПН СКРИПТС ЕООД. The bundled XML Schemas keep their own licences (OASIS and UN/CEFACT notices); see [NOTICE](NOTICE). "PN Scripts" and "PN Invoice" are names of ПН СКРИПТС ЕООД and are not covered by the code licence.

Security issues: see [SECURITY.md](SECURITY.md).
