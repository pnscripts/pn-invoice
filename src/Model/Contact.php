<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

/**
 * Contact point (BG-6, BG-9).
 */
final readonly class Contact
{
    public function __construct(
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
