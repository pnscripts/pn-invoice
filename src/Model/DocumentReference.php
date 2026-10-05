<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

use DateTimeImmutable;

/**
 * Preceding invoice reference (BG-3): mandatory context for most credit notes.
 */
final readonly class DocumentReference
{
    public function __construct(
        public string $id,
        public ?DateTimeImmutable $issueDate = null,
    ) {}
}
