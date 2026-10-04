<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Model;

/**
 * Credit transfer account (BG-17): account identifier (IBAN or proprietary), name and BIC.
 */
final readonly class FinancialAccount
{
    public function __construct(
        public string $id,
        public ?string $name = null,
        public ?string $bic = null,
    ) {}
}
