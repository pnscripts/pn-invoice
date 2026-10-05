<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation;

use Countable;

/**
 * Immutable collection of validation findings from one or more layers.
 */
final readonly class ValidationResult implements Countable
{
    /**
     * @param list<Violation> $violations
     * @param list<Layer>     $layers     layers that actually ran
     */
    public function __construct(
        public array $violations = [],
        public array $layers = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors() === [];
    }

    /**
     * @return list<Violation>
     */
    public function errors(): array
    {
        return $this->bySeverity(Severity::Error);
    }

    /**
     * @return list<Violation>
     */
    public function warnings(): array
    {
        return $this->bySeverity(Severity::Warning);
    }

    /**
     * @return list<Violation>
     */
    public function forRule(string $ruleId): array
    {
        return array_values(array_filter($this->violations, static fn(Violation $v): bool => $v->ruleId === $ruleId));
    }

    /**
     * @return list<string> unique rule ids that produced a finding
     */
    public function ruleIds(): array
    {
        return array_values(array_unique(array_map(static fn(Violation $v): string => $v->ruleId, $this->violations)));
    }

    public function merge(self $other): self
    {
        $layers = $this->layers;
        foreach ($other->layers as $layer) {
            if (!in_array($layer, $layers, true)) {
                $layers[] = $layer;
            }
        }

        return new self([...$this->violations, ...$other->violations], $layers);
    }

    public function count(): int
    {
        return count($this->violations);
    }

    /**
     * @return array{valid: bool, layers: list<string>, errors: int, warnings: int, violations: list<array{rule: string, severity: string, layer: string, message: string, location: string, line: int|null}>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid(),
            'layers' => array_map(static fn(Layer $layer): string => $layer->value, $this->layers),
            'errors' => count($this->errors()),
            'warnings' => count($this->warnings()),
            'violations' => array_map(static fn(Violation $v): array => $v->toArray(), $this->violations),
        ];
    }

    /**
     * @return list<Violation>
     */
    private function bySeverity(Severity $severity): array
    {
        return array_values(array_filter($this->violations, static fn(Violation $v): bool => $v->severity === $severity));
    }
}
