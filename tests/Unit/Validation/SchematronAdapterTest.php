<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Unit\Validation;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Tests\Support\SampleInvoices;
use PnScripts\Invoice\Validation\InvoiceValidator;
use PnScripts\Invoice\Validation\Layer;
use PnScripts\Invoice\Validation\Schematron\CommandSchematronValidator;
use PnScripts\Invoice\Validation\Schematron\SchematronValidator;
use PnScripts\Invoice\Validation\Schematron\SvrlParser;
use PnScripts\Invoice\Validation\Severity;
use PnScripts\Invoice\Validation\Syntax;
use PnScripts\Invoice\Validation\ValidationResult;
use PnScripts\Invoice\Validation\Violation;
use RuntimeException;

final class SchematronAdapterTest extends TestCase
{
    private const string SVRL = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <svrl:schematron-output xmlns:svrl="http://purl.oclc.org/dsdl/svrl">
          <svrl:fired-rule context="/Invoice"/>
          <svrl:failed-assert id="BR-CO-25" flag="fatal" location="/*:Invoice[1]" test="...">
            <svrl:text>[BR-CO-25]-In case the Amount due for payment is positive...</svrl:text>
          </svrl:failed-assert>
          <svrl:failed-assert id="PEPPOL-EN16931-R001" flag="warning" location="/*:Invoice[1]/*:ProfileID[1]">
            <svrl:text>Business process MUST be provided.</svrl:text>
          </svrl:failed-assert>
          <svrl:successful-report id="INFO-1" flag="information" location="/">
            <svrl:text>Note</svrl:text>
          </svrl:successful-report>
        </svrl:schematron-output>
        XML;

    public function testParsesSvrl(): void
    {
        $result = (new SvrlParser())->parse(self::SVRL);

        self::assertCount(3, $result);
        self::assertSame('BR-CO-25', $result->violations[0]->ruleId);
        self::assertSame(Severity::Error, $result->violations[0]->severity);
        self::assertSame('/*:Invoice[1]', $result->violations[0]->location);
        self::assertSame(Layer::Schematron, $result->violations[0]->layer);
        self::assertSame(Severity::Warning, $result->violations[1]->severity);
        self::assertSame(Severity::Info, $result->violations[2]->severity);
        self::assertCount(1, $result->errors());
        self::assertCount(1, $result->warnings());
    }

    public function testRejectsInvalidSvrl(): void
    {
        $this->expectException(RuntimeException::class);
        (new SvrlParser())->parse('not xml');
    }

    public function testCommandValidatorRunsExternalProcessWithoutShell(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'svrl-');
        self::assertIsString($script);
        file_put_contents($script, '<?php if (!is_file($argv[1])) { exit(3); } echo ' . var_export(self::SVRL, true) . ';');

        try {
            $validator = new CommandSchematronValidator([Syntax::UblInvoice->value => [PHP_BINARY, $script, '{file}']]);
            $result = (new InvoiceValidator(schematron: $validator))->validateInvoice(SampleInvoices::standard());
        } finally {
            unlink($script);
        }

        self::assertContains(Layer::Schematron, $result->layers);
        self::assertSame(['BR-CO-25', 'PEPPOL-EN16931-R001', 'INFO-1'], $result->ruleIds());
        self::assertFalse($result->isValid());
    }

    public function testCommandValidatorNeedsConfiguredCommand(): void
    {
        $this->expectException(RuntimeException::class);
        (new CommandSchematronValidator([]))->validate(new DOMDocument(), Syntax::Cii);
    }

    public function testCustomAdapterIsMergedIntoResult(): void
    {
        $adapter = new class implements SchematronValidator {
            public function validate(DOMDocument $document, Syntax $syntax): ValidationResult
            {
                return new ValidationResult([new Violation('X-1', Severity::Warning, 'external', '/', Layer::Schematron)], [Layer::Schematron]);
            }
        };

        $result = (new InvoiceValidator(schematron: $adapter))->validateInvoice(SampleInvoices::exempt(), Syntax::Cii);

        self::assertTrue($result->isValid());
        self::assertSame([Layer::Xml, Layer::Xsd, Layer::BusinessRules, Layer::Schematron], $result->layers);
        self::assertCount(1, $result->warnings());
    }
}
