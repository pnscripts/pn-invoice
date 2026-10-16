<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Validation\InvoiceValidator;
use Pnscripts\Invoice\Validation\Layer;
use Pnscripts\Invoice\Validation\Schematron\CommandSchematronValidator;
use Pnscripts\Invoice\Validation\Syntax;

/**
 * Runs every golden file through the official EN 16931 Schematron (XSLT 2.0, Saxon) via the
 * CommandSchematronValidator adapter, exactly as the README shows. Skipped unless both are set:
 *
 *   PN_INVOICE_SAXON_CP     Java class path of Saxon-HE and its xmlresolver jars
 *                           (e.g. Saxon-HE-12.9.jar:xmlresolver-5.3.3.jar:xmlresolver-5.3.3-data.jar)
 *   PN_INVOICE_EN16931_DIR  a folder with the unpacked official artefacts: ubl/xslt/… and cii/xslt/…
 *
 * Verified on 2026-10-16 with Saxon-HE 12.9, OpenJDK 21 and artefacts 1.3.17.
 */
final class OfficialSchematronTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        return GoldenFileTest::files();
    }

    #[DataProvider('files')]
    public function testGoldenFilePassesTheOfficialSchematron(string $file): void
    {
        $classPath = (string) getenv('PN_INVOICE_SAXON_CP');
        $artefacts = rtrim((string) getenv('PN_INVOICE_EN16931_DIR'), '/');
        if ($classPath === '' || $artefacts === '') {
            self::markTestSkipped('Set PN_INVOICE_SAXON_CP and PN_INVOICE_EN16931_DIR to run the official Schematron.');
        }

        $saxon = static fn(string $xslt): array => ['java', '-cp', $classPath, 'net.sf.saxon.Transform', '-s:{file}', '-xsl:' . $xslt];
        $ubl = $artefacts . '/ubl/xslt/EN16931-UBL-validation.xslt';
        $cii = $artefacts . '/cii/xslt/EN16931-CII-validation.xslt';
        self::assertFileExists($ubl);
        self::assertFileExists($cii);

        $validator = new InvoiceValidator(schematron: new CommandSchematronValidator([
            Syntax::UblInvoice->value => $saxon($ubl),
            Syntax::UblCreditNote->value => $saxon($ubl),
            Syntax::Cii->value => $saxon($cii),
        ]));

        $result = $validator->validateFile(__DIR__ . '/../fixtures/golden/' . $file);

        self::assertContains(Layer::Schematron, $result->layers);
        self::assertSame([], $result->errors(), 'official Schematron errors in ' . $file);
        self::assertTrue($result->isValid());
    }
}
