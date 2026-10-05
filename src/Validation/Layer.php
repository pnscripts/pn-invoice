<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation;

/**
 * The validation layer that produced a result.
 */
enum Layer: string
{
    /** XML well-formedness and syntax detection. */
    case Xml = 'xml';
    /** XML Schema (XSD) validation against the bundled UBL 2.1 / CII D16B schemas. */
    case Xsd = 'xsd';
    /** Pure-PHP implementation of a subset of the EN 16931 business rules. */
    case BusinessRules = 'business-rules';
    /** Results produced by an external Schematron validator adapter. */
    case Schematron = 'schematron';
}
