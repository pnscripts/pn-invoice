<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation;

/**
 * One validation finding.
 */
final readonly class Violation
{
    /**
     * @param string   $ruleId   EN 16931 rule identifier (e.g. "BR-CO-10"), "XSD" or "XML"
     * @param string   $location XPath of the offending node or rule context, when known
     * @param int|null $line     line number in the source document, when known
     */
    public function __construct(
        public string $ruleId,
        public Severity $severity,
        public string $message,
        public string $location = '',
        public Layer $layer = Layer::BusinessRules,
        public ?int $line = null,
    ) {}

    /**
     * @return array{rule: string, severity: string, layer: string, message: string, location: string, line: int|null}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->ruleId,
            'severity' => $this->severity->value,
            'layer' => $this->layer->value,
            'message' => $this->message,
            'location' => $this->location,
            'line' => $this->line,
        ];
    }
}
