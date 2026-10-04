<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

use PnScripts\Invoice\Decimal;

/**
 * A business term read from an XML document: its raw value (null when the element is absent)
 * and the XPath where it was (or would be) found.
 */
final readonly class Field
{
    public function __construct(
        public ?string $value,
        public string $path,
    ) {}

    /** The element exists (it may be empty). */
    public function exists(): bool
    {
        return $this->value !== null;
    }

    /** The element exists and has non-whitespace content. */
    public function filled(): bool
    {
        return $this->value !== null && trim($this->value) !== '';
    }

    public function decimal(): ?Decimal
    {
        return Decimal::tryParse($this->value);
    }

    public function decimalOrZero(): Decimal
    {
        return $this->decimal() ?? Decimal::zero();
    }

    public function normalized(): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->value ?? '') ?? '');
    }
}
