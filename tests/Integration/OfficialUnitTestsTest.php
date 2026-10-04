<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Integration;

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Tests\Support\OfficialTestSet;
use PnScripts\Invoice\Validation\Rules\BusinessRuleValidator;
use PnScripts\Invoice\Validation\Syntax;

/**
 * Runs the official EN 16931 unit tests (ConnectingEurope/eInvoicing-EN16931, test/ folder,
 * EUPL-1.2) against the PHP business rules. Every <success>/<error> expectation that names an
 * implemented rule must hold. Expectations for rules that are not implemented are skipped.
 */
final class OfficialUnitTestsTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../fixtures/official';

    /**
     * @return iterable<string, array{DOMDocument, list<string>, list<string>}>
     */
    public static function cases(): iterable
    {
        $implemented = BusinessRuleValidator::implementedRules();

        foreach (self::files() as $label => $file) {
            foreach (OfficialTestSet::load($file) as $case) {
                $success = array_values(array_filter($case['success'], static fn(string $id): bool => isset($implemented[$id])));
                $error = array_values(array_filter($case['error'], static fn(string $id): bool => isset($implemented[$id])));

                if ($success === [] && $error === []) {
                    continue;
                }

                yield sprintf('%s #%d', $label, $case['index']) => [$case['document'], $success, $error];
            }
        }
    }

    /**
     * @param list<string> $mustPass
     * @param list<string> $mustFail
     */
    #[DataProvider('cases')]
    public function testRuleOutcomeMatchesOfficialExpectation(DOMDocument $document, array $mustPass, array $mustFail): void
    {
        $syntax = Syntax::detect($document);
        self::assertNotNull($syntax);

        $fired = (new BusinessRuleValidator())->validate($document, $syntax)->ruleIds();

        foreach ($mustPass as $ruleId) {
            self::assertNotContains($ruleId, $fired, sprintf('%s must not fire.', $ruleId));
        }
        foreach ($mustFail as $ruleId) {
            self::assertContains($ruleId, $fired, sprintf('%s must fire.', $ruleId));
        }
    }

    public function testEveryImplementedRuleIsCoveredByOfficialTests(): void
    {
        $covered = [];
        foreach (self::files() as $file) {
            foreach (OfficialTestSet::load($file) as $case) {
                foreach ([...$case['success'], ...$case['error']] as $ruleId) {
                    $covered[$ruleId] = true;
                }
            }
        }

        $uncovered = array_diff(array_keys(BusinessRuleValidator::implementedRules()), array_keys($covered));

        self::assertSame([], array_values($uncovered));
    }

    /**
     * @return array<string, string>
     */
    private static function files(): array
    {
        $files = [];
        foreach (['ubl/unit-invoice', 'ubl/unit-creditnote', 'cii/unit'] as $directory) {
            $matches = glob(self::DIRECTORY . '/' . $directory . '/*.xml');
            foreach ($matches === false ? [] : $matches as $file) {
                $files[$directory . '/' . basename($file)] = $file;
            }
        }

        return $files;
    }
}
