<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

final readonly class PartyView
{
    /**
     * @param list<Field> $identifiers party identifiers (BT-29 / BT-46), excluding SEPA creditor ids
     */
    public function __construct(
        public string $path,
        public Field $name,
        public ?string $postalAddressPath,
        public Field $countryCode,
        public Field $vatIdentifier,
        public bool $hasAnyTaxRegistration,
        public Field $legalRegistrationId,
        public array $identifiers,
        public Field $electronicAddress,
        public Field $electronicAddressScheme,
    ) {}
}
