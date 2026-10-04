<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

/**
 * Specification identifier (BT-24, UBL CustomizationID) and business process (BT-23, UBL ProfileID).
 *
 * The values are data: pass any CIUS identifier you need. Two common presets are provided.
 */
final readonly class Specification
{
    public const string EN16931 = 'urn:cen.eu:en16931:2017';

    public const string PEPPOL_BIS_BILLING_3 = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    public const string PEPPOL_BILLING_PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public function __construct(
        public string $customizationId = self::EN16931,
        public ?string $profileId = null,
    ) {}

    public static function en16931(): self
    {
        return new self(self::EN16931);
    }

    /**
     * Peppol BIS Billing 3.0 identifiers. Note: this only sets the identifiers; the
     * Peppol-specific rules (PEPPOL-EN16931-*) are not validated by this library.
     */
    public static function peppolBis3(): self
    {
        return new self(self::PEPPOL_BIS_BILLING_3, self::PEPPOL_BILLING_PROCESS);
    }
}
