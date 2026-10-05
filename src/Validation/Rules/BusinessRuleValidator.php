<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\Rules;

use DOMDocument;
use Pnscripts\Invoice\Validation\Layer;
use Pnscripts\Invoice\Validation\Syntax;
use Pnscripts\Invoice\Validation\ValidationResult;
use Pnscripts\Invoice\Validation\View\InvoiceView;
use Pnscripts\Invoice\Validation\View\ViewFactory;

/**
 * Layer (b): runs the PHP implementation of a documented subset of the EN 16931 business rules.
 *
 * This is NOT a replacement for the official Schematron. See {@see self::implementedRules()}
 * and the README for the exact list; everything else is not checked by this layer.
 */
final readonly class BusinessRuleValidator
{
    /** @var list<RuleSet> */
    private array $ruleSets;

    /**
     * @param list<RuleSet>|null $ruleSets defaults to all implemented rule sets
     * @param list<string>|null  $only     report only these rule ids
     */
    public function __construct(?array $ruleSets = null, private ?array $only = null)
    {
        $this->ruleSets = $ruleSets ?? self::defaultRuleSets();
    }

    /**
     * @return list<RuleSet>
     */
    public static function defaultRuleSets(): array
    {
        $sets = [new CoreRules(), new CalculationRules()];
        foreach (VatCategoryRules::supported() as $category) {
            $sets[] = new VatCategoryRules($category);
        }

        return $sets;
    }

    /**
     * @return array<string, string> every implemented rule id => description
     */
    public static function implementedRules(): array
    {
        $rules = [];
        foreach (self::defaultRuleSets() as $set) {
            $rules += $set->rules();
        }

        return $rules;
    }

    public function validate(DOMDocument $document, Syntax $syntax): ValidationResult
    {
        return $this->validateView(ViewFactory::create($document, $syntax));
    }

    public function validateView(InvoiceView $view): ValidationResult
    {
        $descriptions = [];
        foreach ($this->ruleSets as $set) {
            $descriptions += $set->rules();
        }

        $report = new Report($descriptions, $this->only);
        foreach ($this->ruleSets as $set) {
            $set->check($view, $report);
        }

        return new ValidationResult($report->violations(), [Layer::BusinessRules]);
    }
}
