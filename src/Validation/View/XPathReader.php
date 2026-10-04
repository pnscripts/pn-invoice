<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Shared XPath helpers for the syntax bindings.
 *
 * @internal
 */
final readonly class XPathReader
{
    private DOMXPath $xpath;

    /**
     * @param array<string, string> $namespaces
     */
    public function __construct(DOMDocument $document, array $namespaces)
    {
        $this->xpath = new DOMXPath($document);
        foreach ($namespaces as $prefix => $uri) {
            $this->xpath->registerNamespace($prefix, $uri);
        }
    }

    public function first(string $expression, DOMNode $context): ?DOMNode
    {
        $nodes = $this->xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    /**
     * @return list<DOMElement>
     */
    public function elements(string $expression, DOMNode $context): array
    {
        $nodes = $this->xpath->query($expression, $context);
        if ($nodes === false) {
            return [];
        }

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function exists(string $expression, DOMNode $context): bool
    {
        return $this->first($expression, $context) !== null;
    }

    public function field(string $expression, DOMNode $context): Field
    {
        $node = $this->first($expression, $context);

        if ($node === null) {
            $first = explode(' | ', $expression)[0];

            return new Field(null, self::path($context) . '/' . $first);
        }

        return new Field($node->textContent, self::path($node));
    }

    public static function path(DOMNode $node): string
    {
        return $node->getNodePath() ?? '';
    }

    /**
     * XPath 1.0 predicate equivalent of the Schematron test "normalize-space(upper-case(X)) = 'VAT'".
     */
    public static function isVat(string $expression): string
    {
        return sprintf("translate(normalize-space(%s), 'vat', 'VAT') = 'VAT'", $expression);
    }
}
