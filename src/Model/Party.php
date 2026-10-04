<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

/**
 * Seller (BG-4), Buyer (BG-7), Payee (BG-10) or Seller tax representative (BG-11).
 */
final readonly class Party
{
    /**
     * @param list<Identifier> $identifiers Party identifiers (BT-29 / BT-46 / BT-60)
     */
    public function __construct(
        public string $name,
        public ?Address $address = null,
        public ?string $vatId = null,
        public ?string $taxRegistrationId = null,
        public ?Identifier $legalRegistrationId = null,
        public ?string $tradingName = null,
        public ?Identifier $electronicAddress = null,
        public array $identifiers = [],
        public ?Contact $contact = null,
        public ?string $legalForm = null,
    ) {}
}
