<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation;

use DOMDocument;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Validation\Rules\BusinessRuleValidator;
use Pnscripts\Invoice\Validation\Schematron\SchematronValidator;
use Pnscripts\Invoice\Validation\Xsd\XsdValidator;
use Pnscripts\Invoice\Writer\CiiWriter;
use Pnscripts\Invoice\Writer\UblWriter;

/**
 * Validates UBL 2.1 / CII D16B e-invoices in layers:
 *
 *  1. XML well-formedness and syntax detection
 *  2. XSD validation against the official schemas
 *  3. PHP business rules (documented subset of EN 16931)
 *  4. optional external Schematron validator
 *
 * Passing all layers does NOT certify EN 16931 conformance unless a full Schematron
 * validator is configured; see the README for the scope.
 */
final readonly class InvoiceValidator
{
    public function __construct(
        private XsdValidator $xsd = new XsdValidator(),
        private BusinessRuleValidator $rules = new BusinessRuleValidator(),
        private ?SchematronValidator $schematron = null,
        private bool $runXsd = true,
        private bool $runRules = true,
    ) {}

    public function validateXml(string $xml): ValidationResult
    {
        $document = XmlLoader::load($xml);
        if (!$document instanceof DOMDocument) {
            return new ValidationResult($document, [Layer::Xml]);
        }

        return $this->validateDocument($document);
    }

    public function validateFile(string $path): ValidationResult
    {
        if (!is_file($path) || !is_readable($path)) {
            return new ValidationResult([new Violation('XML', Severity::Error, sprintf('File "%s" is not readable.', $path), '', Layer::Xml)], [Layer::Xml]);
        }

        return $this->validateXml((string) file_get_contents($path));
    }

    public function validateDocument(DOMDocument $document): ValidationResult
    {
        $syntax = Syntax::detect($document);
        if ($syntax === null) {
            return new ValidationResult([new Violation(
                'XML',
                Severity::Error,
                'Unknown document type: expected a UBL 2.1 Invoice, UBL 2.1 CreditNote or UN/CEFACT CrossIndustryInvoice root element.',
                '/*',
                Layer::Xml,
            )], [Layer::Xml]);
        }

        $result = new ValidationResult([], [Layer::Xml]);

        if ($this->runXsd) {
            $result = $result->merge($this->xsd->validate($document, $syntax));
        }

        if ($this->runRules) {
            $result = $result->merge($this->rules->validate($document, $syntax));
        }

        if ($this->schematron !== null) {
            $result = $result->merge($this->schematron->validate($document, $syntax));
        }

        return $result;
    }

    /**
     * Serialises the model with the writer for the requested syntax and validates the result.
     */
    public function validateInvoice(Invoice $invoice, Syntax $syntax = Syntax::UblInvoice): ValidationResult
    {
        $writer = $syntax === Syntax::Cii ? new CiiWriter() : new UblWriter();

        return $this->validateDocument($writer->toDocument($invoice));
    }
}
