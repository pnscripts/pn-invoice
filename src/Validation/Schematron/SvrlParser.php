<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Schematron;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PnScripts\Invoice\Validation\Layer;
use PnScripts\Invoice\Validation\Severity;
use PnScripts\Invoice\Validation\ValidationResult;
use PnScripts\Invoice\Validation\Violation;
use PnScripts\Invoice\Validation\XmlLoader;
use RuntimeException;

/**
 * Converts Schematron Validation Report Language (SVRL) output into a {@see ValidationResult}.
 *
 * svrl:failed-assert and svrl:successful-report become violations; the "flag" attribute maps
 * to severity (fatal/error => error, warning => warning, anything else => info).
 */
final class SvrlParser
{
    public const string NS_SVRL = 'http://purl.oclc.org/dsdl/svrl';

    public function parse(string $svrl): ValidationResult
    {
        $document = XmlLoader::load($svrl);
        if (!$document instanceof DOMDocument) {
            throw new RuntimeException('The Schematron validator did not return well-formed SVRL.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('svrl', self::NS_SVRL);

        $nodes = $xpath->query('//svrl:failed-assert | //svrl:successful-report');
        $violations = [];

        foreach ($nodes === false ? [] : $nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $textNode = $xpath->query('svrl:text', $node);
            $first = $textNode !== false ? $textNode->item(0) : null;
            $text = $first instanceof DOMElement ? $first->textContent : $node->textContent;

            $violations[] = new Violation(
                $node->getAttribute('id') !== '' ? $node->getAttribute('id') : 'SCHEMATRON',
                match (strtolower($node->getAttribute('flag'))) {
                    'fatal', 'error', '' => Severity::Error,
                    'warning' => Severity::Warning,
                    default => Severity::Info,
                },
                trim(preg_replace('/\s+/', ' ', $text) ?? $text),
                $node->getAttribute('location'),
                Layer::Schematron,
            );
        }

        return new ValidationResult($violations, [Layer::Schematron]);
    }
}
