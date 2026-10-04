<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Xsd;

use DOMDocument;
use LibXMLError;
use PnScripts\Invoice\Validation\Layer;
use PnScripts\Invoice\Validation\Severity;
use PnScripts\Invoice\Validation\Syntax;
use PnScripts\Invoice\Validation\ValidationResult;
use PnScripts\Invoice\Validation\Violation;

/**
 * Layer (a): validates a document against the official UBL 2.1 or CII D16B XML Schema using libxml.
 */
final readonly class XsdValidator
{
    public function __construct(private SchemaLocator $schemas = new SchemaLocator()) {}

    public function validate(DOMDocument $document, Syntax $syntax): ValidationResult
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document->schemaValidate($this->schemas->pathFor($syntax));
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $violations = array_map(
            static fn(LibXMLError $error): Violation => new Violation(
                'XSD',
                $error->level === LIBXML_ERR_WARNING ? Severity::Warning : Severity::Error,
                trim($error->message),
                '',
                Layer::Xsd,
                $error->line > 0 ? $error->line : null,
            ),
            $errors,
        );

        return new ValidationResult($violations, [Layer::Xsd]);
    }
}
