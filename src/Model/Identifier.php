<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

/**
 * An identifier with an optional scheme (for example an electronic address with EAS code
 * "0088", a GLN with ICD "0088", or a VAT number without scheme).
 */
final readonly class Identifier
{
    public function __construct(
        public string $value,
        public ?string $scheme = null,
    ) {}
}
