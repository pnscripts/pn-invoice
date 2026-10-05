<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Writer;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Small helper around DOMDocument that resolves prefixes to namespaces and skips empty values.
 *
 * @internal
 */
final class DomBuilder
{
    public readonly DOMDocument $document;

    /**
     * @param array<string, string> $namespaces prefix => namespace URI; the empty prefix is the default namespace
     */
    public function __construct(private readonly array $namespaces)
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;
    }

    /**
     * @param array<string, string|null> $attributes
     */
    public function root(string $qualifiedName, array $attributes = []): DOMElement
    {
        $element = $this->create($qualifiedName, null, $attributes);

        foreach ($this->namespaces as $prefix => $uri) {
            $element->setAttributeNS('http://www.w3.org/2000/xmlns/', $prefix === '' ? 'xmlns' : 'xmlns:' . $prefix, $uri);
        }

        $this->document->appendChild($element);

        return $element;
    }

    /**
     * Appends a child element; the text is escaped by DOM.
     *
     * @param array<string, string|null> $attributes null attribute values are skipped
     */
    public function add(DOMElement $parent, string $qualifiedName, ?string $text = null, array $attributes = []): DOMElement
    {
        $element = $this->create($qualifiedName, $text, $attributes);
        $parent->appendChild($element);

        return $element;
    }

    /**
     * Appends a child element only when the text is neither null nor empty.
     *
     * @param array<string, string|null> $attributes
     */
    public function addOptional(DOMElement $parent, string $qualifiedName, ?string $text, array $attributes = []): ?DOMElement
    {
        if ($text === null || $text === '') {
            return null;
        }

        return $this->add($parent, $qualifiedName, $text, $attributes);
    }

    public function toXml(): string
    {
        $xml = $this->document->saveXML();

        if ($xml === false) {
            throw new RuntimeException('Could not serialise the XML document.');
        }

        return $xml;
    }

    /**
     * @param array<string, string|null> $attributes
     */
    private function create(string $qualifiedName, ?string $text, array $attributes): DOMElement
    {
        $prefix = str_contains($qualifiedName, ':') ? strstr($qualifiedName, ':', true) : '';
        $namespace = $this->namespaces[$prefix] ?? throw new InvalidArgumentException(sprintf('Unknown namespace prefix "%s".', $prefix));

        $element = $this->document->createElementNS($namespace, $qualifiedName);

        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }

        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $element->setAttribute($name, $value);
            }
        }

        return $element;
    }
}
