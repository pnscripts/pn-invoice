<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation;

use DOMDocument;
use LibXMLError;

/**
 * Parses XML safely: no network access, no external entity expansion, huge documents refused by libxml defaults.
 */
final class XmlLoader
{
    /**
     * @return DOMDocument|list<Violation> the document, or the parse errors
     */
    public static function load(string $xml): DOMDocument|array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded && $document->doctype === null) {
            return $document;
        }

        if ($loaded) {
            return [new Violation('XML', Severity::Error, 'DOCTYPE declarations are not allowed in e-invoices.', '', Layer::Xml)];
        }

        if ($errors === []) {
            return [new Violation('XML', Severity::Error, 'The document is empty or not well-formed XML.', '', Layer::Xml)];
        }

        return array_map(
            static fn(LibXMLError $error): Violation => new Violation('XML', Severity::Error, trim($error->message), '', Layer::Xml, $error->line),
            $errors,
        );
    }
}
