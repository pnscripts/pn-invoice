<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Validation;

enum Severity: string
{
    /** The document violates a mandatory rule ("fatal" in the official Schematron). */
    case Error = 'error';
    /** The document is accepted but should be corrected. */
    case Warning = 'warning';
    /** Informational message. */
    case Info = 'info';
}
