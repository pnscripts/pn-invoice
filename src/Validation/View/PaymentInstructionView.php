<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Validation\View;

final readonly class PaymentInstructionView
{
    /**
     * @param list<Field> $creditTransferAccounts one Field per credit transfer account (BG-17), value = account id (BT-84)
     */
    public function __construct(
        public string $path,
        public Field $typeCode,
        public array $creditTransferAccounts,
    ) {}

    public function isCreditTransfer(): bool
    {
        return in_array($this->typeCode->normalized(), ['30', '58'], true);
    }
}
