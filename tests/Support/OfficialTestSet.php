<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Reads the official EN 16931 unit test files (VEFA validator "testSet" format) shipped in
 * tests/fixtures/official. Each test case wraps an (often partial) invoice and lists the rule
 * ids that must succeed or fail.
 */
final class OfficialTestSet
{
    private const string NS = 'http://difi.no/xsd/vefa/validator/1.0';

    /**
     * @return list<array{index: int, description: string, document: DOMDocument, success: list<string>, error: list<string>}>
     */
    public static function load(string $file): array
    {
        $source = new DOMDocument();
        if (!$source->load($file, LIBXML_NONET)) {
            throw new RuntimeException('Cannot read ' . $file);
        }

        $xpath = new DOMXPath($source);
        $xpath->registerNamespace('t', self::NS);

        $cases = [];
        $index = 0;
        $tests = $xpath->query('/t:testSet/t:test');
        foreach ($tests === false ? [] : $tests as $test) {
            if (!$test instanceof DOMElement) {
                continue;
            }
            ++$index;

            $invoice = null;
            foreach ($test->childNodes as $child) {
                if ($child instanceof DOMElement && $child->namespaceURI !== self::NS) {
                    $invoice = $child;
                    break;
                }
            }
            if ($invoice === null) {
                continue;
            }

            $document = new DOMDocument();
            $document->appendChild($document->importNode($invoice, true));
            // Re-parse so node paths and namespaces behave like a standalone file.
            $standalone = new DOMDocument();
            $standalone->loadXML((string) $document->saveXML(), LIBXML_NONET);

            $cases[] = [
                'index' => $index,
                'description' => trim((string) $xpath->evaluate('string(t:assert/t:description)', $test)),
                'document' => $standalone,
                'success' => self::texts($xpath, 't:assert/t:success', $test),
                'error' => self::texts($xpath, 't:assert/t:error', $test),
            ];
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function texts(DOMXPath $xpath, string $expression, DOMElement $context): array
    {
        $values = [];
        $nodes = $xpath->query($expression, $context);
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $values[] = trim($node->textContent);
            }
        }

        return $values;
    }
}
