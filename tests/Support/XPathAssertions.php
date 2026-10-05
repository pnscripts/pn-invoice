<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Support;

use DOMDocument;
use DOMXPath;
use Pnscripts\Invoice\Writer\CiiWriter;
use Pnscripts\Invoice\Writer\UblWriter;

final class XPathAssertions
{
    public static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('inv', UblWriter::NS_INVOICE);
        $xpath->registerNamespace('cn', UblWriter::NS_CREDIT_NOTE);
        $xpath->registerNamespace('cac', UblWriter::NS_CAC);
        $xpath->registerNamespace('cbc', UblWriter::NS_CBC);
        $xpath->registerNamespace('rsm', CiiWriter::NS_RSM);
        $xpath->registerNamespace('ram', CiiWriter::NS_RAM);
        $xpath->registerNamespace('udt', CiiWriter::NS_UDT);
        $xpath->registerNamespace('qdt', CiiWriter::NS_QDT);

        return $xpath;
    }

    public static function value(DOMDocument $document, string $expression): string
    {
        $result = self::xpath($document)->evaluate('string(' . $expression . ')');

        return is_string($result) ? $result : '';
    }

    public static function count(DOMDocument $document, string $expression): int
    {
        $result = self::xpath($document)->evaluate('count(' . $expression . ')');

        return is_float($result) || is_int($result) ? (int) $result : 0;
    }

    public static function load(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadXML($xml, LIBXML_NONET);

        return $document;
    }
}
