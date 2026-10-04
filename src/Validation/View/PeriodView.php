<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation\View;

final readonly class PeriodView
{
    public function __construct(
        public string $path,
        public Field $start,
        public Field $end,
        public bool $hasDescriptionCode = false,
    ) {}
}
