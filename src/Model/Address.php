<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

/**
 * Postal address (BG-5, BG-8, BG-12, BG-15). Country code is ISO 3166-1 alpha-2.
 */
final readonly class Address
{
    public function __construct(
        public string $countryCode,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $line3 = null,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?string $countrySubdivision = null,
    ) {}
}
