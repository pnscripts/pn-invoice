<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Invoicing period (BG-14) or invoice line period (BG-26).
 */
final readonly class Period
{
    public function __construct(
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
    ) {
        if ($start === null && $end === null) {
            throw new InvalidArgumentException('A period needs a start date, an end date or both.');
        }
    }
}
