<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\Rules;

use PnScripts\Invoice\Validation\Layer;
use PnScripts\Invoice\Validation\Severity;
use PnScripts\Invoice\Validation\Violation;

/**
 * Collects rule failures while a {@see RuleSet} runs.
 */
final class Report
{
    /** @var list<Violation> */
    private array $violations = [];

    /**
     * @param array<string, string> $descriptions rule id => description
     * @param list<string>|null     $only         restrict reporting to these rule ids
     */
    public function __construct(
        private readonly array $descriptions,
        private readonly ?array $only = null,
    ) {}

    public function fail(string $ruleId, string $location, ?string $detail = null): void
    {
        if ($this->only !== null && !in_array($ruleId, $this->only, true)) {
            return;
        }

        $message = $this->descriptions[$ruleId] ?? $ruleId;
        if ($detail !== null) {
            $message .= ' ' . $detail;
        }

        $this->violations[] = new Violation($ruleId, Severity::Error, $message, $location, Layer::BusinessRules);
    }

    /**
     * Records a failure when the condition is false.
     */
    public function assert(bool $condition, string $ruleId, string $location, ?string $detail = null): void
    {
        if (!$condition) {
            $this->fail($ruleId, $location, $detail);
        }
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
