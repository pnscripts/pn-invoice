<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\Schematron;

use DOMDocument;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Validation\ValidationResult;

/**
 * Optional layer (c): full Schematron validation.
 *
 * The official EN 16931 Schematron (and CIUS such as Peppol BIS or XRechnung) requires an
 * XSLT 2.0 processor, which PHP's ext-xsl (XSLT 1.0) cannot run. Implement this interface to
 * delegate to Saxon, a validation service, or any other tool, and pass it to
 * {@see \Pnscripts\Invoice\Validation\InvoiceValidator}. Results should use
 * {@see \Pnscripts\Invoice\Validation\Layer::Schematron}; {@see SvrlParser} converts standard SVRL output.
 */
interface SchematronValidator
{
    public function validate(DOMDocument $document, Syntax $syntax): ValidationResult;
}
