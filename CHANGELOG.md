# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-04

### Added

- Syntax-neutral invoice model for the EN 16931 core: invoice and credit note, seller, buyer,
  payee, seller tax representative, lines with item and price details, line and document level
  allowances and charges, VAT categories S, Z, E, AE, K, G, O, L and M as data, credit transfer
  payment means, payment terms, invoicing and line periods, preceding invoice references.
- `Decimal` value object (bcmath) for all amounts, quantities, prices and rates.
- `InvoiceCalculator`: line net amounts, document totals and VAT breakdown per EN 16931.
- `UblWriter` (UBL 2.1 Invoice and CreditNote) and `CiiWriter` (UN/CEFACT CII D16B) with
  configurable specification identifier (EN 16931, Peppol BIS Billing 3.0, or any CIUS id).
- Layered validation with structured results (rule id, severity, layer, message, XPath, line):
  XML safety checks, XSD validation against the bundled official UBL 2.1 and CII D16B schemas,
  and 104 EN 16931 business rules in pure PHP (core BR, BR-CO, BR-S, BR-Z, BR-E, BR-AE).
- `SchematronValidator` adapter interface, `SvrlParser` and `CommandSchematronValidator` for
  plugging in Saxon or another external XSLT 2.0 validator.
- `bin/pn-invoice` CLI: `validate` (text or JSON, exit code 1 on errors) and `rules`.
- Test suite with golden files, the official EN 16931 example invoices and the official
  EN 16931 unit tests (EUPL-1.2, kept separately under `tests/fixtures/official/`).
