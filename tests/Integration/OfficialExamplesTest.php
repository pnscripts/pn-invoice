<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Validation\InvoiceValidator;

/**
 * The official EN 16931 example invoices (CEN/TC 434 and others, EUPL-1.2, see
 * tests/fixtures/official/NOTICE) must pass the XSD and every implemented business rule.
 */
final class OfficialExamplesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function examples(): iterable
    {
        $ubl = glob(__DIR__ . '/../fixtures/official/ubl/examples/*');
        $cii = glob(__DIR__ . '/../fixtures/official/cii/examples/*');
        $files = array_merge($ubl === false ? [] : $ubl, $cii === false ? [] : $cii);

        foreach ($files as $file) {
            yield basename(dirname($file, 2)) . '/' . basename($file) => [$file];
        }
    }

    #[DataProvider('examples')]
    public function testOfficialExampleIsValid(string $file): void
    {
        $result = (new InvoiceValidator())->validateFile($file);

        self::assertSame([], $result->toArray()['violations']);
    }
}
