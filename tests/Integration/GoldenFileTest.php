<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Model\Specification;
use Pnscripts\Invoice\Tests\Support\SampleInvoices;
use Pnscripts\Invoice\Validation\InvoiceValidator;
use Pnscripts\Invoice\Validation\Layer;
use Pnscripts\Invoice\Writer\CiiWriter;
use Pnscripts\Invoice\Writer\InvoiceWriter;
use Pnscripts\Invoice\Writer\UblWriter;

/**
 * Golden files: the writers must keep producing byte-identical XML, and every golden file
 * must pass the official XSD and all implemented business rules.
 *
 * Regenerate after an intentional change with: PN_INVOICE_UPDATE_GOLDEN=1 vendor/bin/phpunit
 */
final class GoldenFileTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../fixtures/golden';

    /**
     * @return iterable<string, array{string, Invoice, InvoiceWriter}>
     */
    public static function cases(): iterable
    {
        $ubl = new UblWriter();
        $cii = new CiiWriter();

        yield 'ubl standard' => ['ubl-invoice-standard.xml', SampleInvoices::standard(), $ubl];
        yield 'ubl peppol' => ['ubl-invoice-peppol.xml', SampleInvoices::standard(Specification::peppolBis3()), $ubl];
        yield 'ubl reverse charge' => ['ubl-invoice-reverse-charge.xml', SampleInvoices::reverseCharge(), $ubl];
        yield 'ubl exempt' => ['ubl-invoice-exempt.xml', SampleInvoices::exempt(), $ubl];
        yield 'ubl credit note' => ['ubl-credit-note.xml', SampleInvoices::creditNote(), $ubl];
        yield 'cii standard' => ['cii-invoice-standard.xml', SampleInvoices::standard(), $cii];
        yield 'cii reverse charge' => ['cii-invoice-reverse-charge.xml', SampleInvoices::reverseCharge(), $cii];
        yield 'cii exempt' => ['cii-invoice-exempt.xml', SampleInvoices::exempt(), $cii];
        yield 'cii credit note' => ['cii-credit-note.xml', SampleInvoices::creditNote(), $cii];
    }

    #[DataProvider('cases')]
    public function testWriterOutputMatchesGoldenFile(string $file, Invoice $invoice, InvoiceWriter $writer): void
    {
        $path = self::DIRECTORY . '/' . $file;
        $xml = $writer->write($invoice);

        if (getenv('PN_INVOICE_UPDATE_GOLDEN') === '1') {
            file_put_contents($path, $xml);
        }

        self::assertFileExists($path);
        self::assertSame(file_get_contents($path), $xml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        foreach (self::cases() as $name => [$file]) {
            yield $name => [$file];
        }
    }

    #[DataProvider('files')]
    public function testGoldenFilePassesXsdAndBusinessRules(string $file): void
    {
        $result = (new InvoiceValidator())->validateFile(self::DIRECTORY . '/' . $file);

        self::assertSame([Layer::Xml, Layer::Xsd, Layer::BusinessRules], $result->layers);
        self::assertSame([], $result->toArray()['violations']);
        self::assertTrue($result->isValid());
    }
}
