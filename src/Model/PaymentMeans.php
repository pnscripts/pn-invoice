<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

/**
 * Payment instructions (BG-16). The type code is UNTDID 4461 (e.g. "30" credit transfer,
 * "58" SEPA credit transfer, "49" direct debit, "48" card, "10" cash).
 */
final readonly class PaymentMeans
{
    public function __construct(
        public string $typeCode,
        public ?string $typeText = null,
        public ?string $remittanceInformation = null,
        public ?FinancialAccount $account = null,
    ) {}

    public static function creditTransfer(string $iban, ?string $accountName = null, ?string $bic = null, ?string $remittanceInformation = null, bool $sepa = true): self
    {
        return new self($sepa ? '58' : '30', null, $remittanceInformation, new FinancialAccount($iban, $accountName, $bic));
    }
}
