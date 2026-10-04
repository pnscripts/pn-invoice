<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Unit\Validation;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Tests\Support\SampleInvoices;
use PnScripts\Invoice\Validation\InvoiceValidator;
use PnScripts\Invoice\Validation\Layer;
use PnScripts\Invoice\Validation\Syntax;
use PnScripts\Invoice\Validation\XmlLoader;
use PnScripts\Invoice\Validation\Xsd\SchemaLocator;
use PnScripts\Invoice\Validation\Xsd\XsdValidator;
use PnScripts\Invoice\Writer\UblWriter;
use RuntimeException;

final class XmlAndXsdTest extends TestCase
{
    public function testRejectsMalformedXml(): void
    {
        $result = (new InvoiceValidator())->validateXml('<Invoice><unclosed></Invoice>');

        self::assertFalse($result->isValid());
        self::assertSame([Layer::Xml], $result->layers);
        self::assertSame('XML', $result->violations[0]->ruleId);
        self::assertSame(1, $result->violations[0]->line);
    }

    public function testRejectsEmptyInput(): void
    {
        self::assertFalse((new InvoiceValidator())->validateXml('')->isValid());
    }

    public function testRefusesDoctypeAndExternalEntities(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">&e;</Invoice>';

        $result = XmlLoader::load($xml);

        self::assertIsArray($result);
        self::assertStringContainsString('DOCTYPE', $result[0]->message);
    }

    public function testRejectsUnknownRootElement(): void
    {
        $result = (new InvoiceValidator())->validateXml('<?xml version="1.0"?><Order xmlns="urn:example"/>');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('Unknown document type', $result->violations[0]->message);
    }

    public function testDetectsSyntax(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::creditNote());

        self::assertSame(Syntax::UblCreditNote, Syntax::detect($document));
        self::assertSame('UBL 2.1 CreditNote', Syntax::UblCreditNote->label());
        self::assertTrue(Syntax::UblInvoice->isUbl());
        self::assertFalse(Syntax::Cii->isUbl());
        self::assertNull(Syntax::detect(new DOMDocument()));
    }

    public function testXsdReportsSchemaViolationsWithLineNumbers(): void
    {
        $xml = (new UblWriter())->write(SampleInvoices::reverseCharge());
        // Move IssueDate after the type code: wrong element order for the schema.
        $xml = preg_replace('#(<cbc:IssueDate>[^<]+</cbc:IssueDate>)(\s*)(<cbc:DueDate>[^<]+</cbc:DueDate>\s*<cbc:InvoiceTypeCode>[^<]+</cbc:InvoiceTypeCode>)#', '$3$2$1', $xml);
        self::assertIsString($xml);

        $document = new DOMDocument();
        $document->loadXML($xml);
        $result = (new XsdValidator())->validate($document, Syntax::UblInvoice);

        self::assertFalse($result->isValid());
        self::assertSame('XSD', $result->violations[0]->ruleId);
        self::assertSame(Layer::Xsd, $result->violations[0]->layer);
        self::assertNotNull($result->violations[0]->line);
    }

    public function testValidatorCanSkipLayers(): void
    {
        $validator = new InvoiceValidator(runXsd: false, runRules: false);
        $result = $validator->validateInvoice(SampleInvoices::standard());

        self::assertTrue($result->isValid());
        self::assertSame([Layer::Xml], $result->layers);
    }

    public function testValidateFileReportsMissingFile(): void
    {
        $result = (new InvoiceValidator())->validateFile('/nonexistent/invoice.xml');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('not readable', $result->violations[0]->message);
    }

    public function testSchemaLocatorFailsForMissingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        (new SchemaLocator('/nonexistent'))->pathFor(Syntax::Cii);
    }

    public function testResultSerialisation(): void
    {
        $result = (new InvoiceValidator())->validateXml('<broken');
        $array = $result->toArray();

        self::assertFalse($array['valid']);
        self::assertSame(['xml'], $array['layers']);
        self::assertSame(count($result), $array['errors']);
        self::assertSame('xml', $array['violations'][0]['layer']);
    }
}
