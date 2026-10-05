<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Tests\Unit\Validation;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pnscripts\Invoice\Model\Invoice;
use Pnscripts\Invoice\Tests\Support\SampleInvoices;
use Pnscripts\Invoice\Tests\Support\XPathAssertions as X;
use Pnscripts\Invoice\Validation\Layer;
use Pnscripts\Invoice\Validation\Rules\BusinessRuleValidator;
use Pnscripts\Invoice\Validation\Rules\CalculationRules;
use Pnscripts\Invoice\Validation\Rules\VatCategoryRules;
use Pnscripts\Invoice\Validation\Severity;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Writer\CiiWriter;
use Pnscripts\Invoice\Writer\UblWriter;

/**
 * Hand-written rule tests on documents produced by the writers and then tampered with.
 * The exhaustive per-rule checks against the official EN 16931 test suite live in
 * tests/Integration/OfficialUnitTestsTest.php.
 */
final class BusinessRuleValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{Invoice}>
     */
    public static function samples(): iterable
    {
        yield 'standard' => [SampleInvoices::standard()];
        yield 'reverse charge' => [SampleInvoices::reverseCharge()];
        yield 'exempt' => [SampleInvoices::exempt()];
        yield 'credit note' => [SampleInvoices::creditNote()];
    }

    #[DataProvider('samples')]
    public function testGeneratedDocumentsPassAllImplementedRules(Invoice $invoice): void
    {
        $validator = new BusinessRuleValidator();

        $ubl = (new UblWriter())->toDocument($invoice);
        $ublSyntax = Syntax::detect($ubl);
        self::assertNotNull($ublSyntax);
        self::assertSame([], $validator->validate($ubl, $ublSyntax)->ruleIds());

        $cii = (new CiiWriter())->toDocument($invoice);
        self::assertSame([], $validator->validate($cii, Syntax::Cii)->ruleIds());
    }

    public function testDetectsWrongAmountDueInUbl(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $this->set($document, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount', '1000.00');

        $result = (new BusinessRuleValidator())->validate($document, Syntax::UblInvoice);
        $violations = $result->forRule('BR-CO-16');

        self::assertFalse($result->isValid());
        self::assertSame(['BR-CO-16'], $result->ruleIds());
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(Layer::BusinessRules, $violations[0]->layer);
        self::assertSame('/*/cac:LegalMonetaryTotal/cbc:PayableAmount', $violations[0]->location);
        self::assertStringContainsString('Expected 1454.36', $violations[0]->message);
    }

    public function testDetectsWrongLineTotalInCii(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::standard());
        $this->set($document, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount', '1302.05');

        $ids = (new BusinessRuleValidator())->validate($document, Syntax::Cii)->ruleIds();

        self::assertContains('BR-CO-10', $ids);
        self::assertContains('BR-CO-13', $ids);
    }

    public function testDetectsWrongStandardRateBreakdown(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $subtotal = '/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal[1]';
        $this->set($document, $subtotal . '/cbc:TaxableAmount', '1300.00');
        $this->set($document, $subtotal . '/cbc:TaxAmount', '260.00');

        $ids = (new BusinessRuleValidator())->validate($document, Syntax::UblInvoice)->ruleIds();

        self::assertContains('BR-S-08', $ids);
        self::assertContains('BR-CO-14', $ids);
    }

    public function testDetectsMissingReverseChargeBuyerIdentifier(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::reverseCharge());
        $this->remove($document, '/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme');

        $ids = (new BusinessRuleValidator())->validate($document, Syntax::UblInvoice)->ruleIds();

        self::assertSame(['BR-AE-02'], $ids);
    }

    public function testDetectsMissingExemptionReason(): void
    {
        $document = (new CiiWriter())->toDocument(SampleInvoices::exempt());
        $this->remove($document, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason');
        $this->remove($document, '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode');

        self::assertSame(['BR-E-10'], (new BusinessRuleValidator())->validate($document, Syntax::Cii)->ruleIds());
    }

    public function testDetectsInvalidVatIdentifierPrefix(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $this->set($document, '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID', '123456789');

        self::assertSame(['BR-CO-09'], (new BusinessRuleValidator())->validate($document, Syntax::UblInvoice)->ruleIds());
    }

    public function testRestrictsToSelectedRules(): void
    {
        $document = (new UblWriter())->toDocument(SampleInvoices::standard());
        $this->set($document, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount', '1');

        $result = (new BusinessRuleValidator(null, ['BR-CO-10']))->validate($document, Syntax::UblInvoice);

        self::assertTrue($result->isValid());
    }

    public function testCatalogueListsEveryImplementedRule(): void
    {
        $rules = BusinessRuleValidator::implementedRules();

        self::assertArrayHasKey('BR-01', $rules);
        self::assertArrayHasKey('BR-CO-17', $rules);
        foreach (['S', 'Z', 'E', 'AE'] as $category) {
            for ($i = 1; $i <= 10; ++$i) {
                self::assertArrayHasKey(sprintf('BR-%s-%02d', $category, $i), $rules);
            }
        }
        self::assertArrayNotHasKey('BR-O-01', $rules);
        self::assertCount(104, $rules);
    }

    public function testUnsupportedVatCategoryRulesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VatCategoryRules(\Pnscripts\Invoice\Model\VatCategory::NotSubjectToVat);
    }

    public function testVatAmountTolerancesMirrorOfficialBindings(): void
    {
        $d = static fn(string $v): \Pnscripts\Invoice\Decimal => \Pnscripts\Invoice\Decimal::of($v);

        self::assertTrue(CalculationRules::vatAmountMatches($d('100'), $d('20'), $d('20')));
        self::assertTrue(CalculationRules::vatAmountMatches($d('100'), $d('20.99'), $d('20')));
        self::assertFalse(CalculationRules::vatAmountMatches($d('100'), $d('21'), $d('20')));
        self::assertTrue(CalculationRules::vatAmountMatches($d('100'), $d('21'), $d('20'), true));
        self::assertTrue(CalculationRules::vatAmountMatches($d('100'), $d('0'), null));
        self::assertFalse(CalculationRules::vatAmountMatches($d('100'), $d('5'), $d('0')));
        self::assertFalse(CalculationRules::vatAmountMatches($d('100'), null, $d('20')));
    }

    private function set(DOMDocument $document, string $expression, string $value): void
    {
        $nodes = X::xpath($document)->query($expression);
        self::assertNotFalse($nodes);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node, 'Node not found: ' . $expression);
        $node->textContent = $value;
    }

    private function remove(DOMDocument $document, string $expression): void
    {
        $nodes = X::xpath($document)->query($expression);
        self::assertNotFalse($nodes);
        $node = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node, 'Node not found: ' . $expression);
        $node->parentNode?->removeChild($node);
    }
}
