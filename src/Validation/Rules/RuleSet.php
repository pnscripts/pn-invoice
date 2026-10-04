<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Rules;

use PnScripts\Invoice\Validation\View\InvoiceView;

/**
 * A group of EN 16931 business rules implemented in PHP against the syntax-neutral {@see InvoiceView}.
 */
interface RuleSet
{
    /**
     * @return array<string, string> rule id => short description of what is checked
     */
    public function rules(): array;

    public function check(InvoiceView $view, Report $report): void;
}
