<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Writer;

use Pnscripts\Invoice\Decimal;

/**
 * Number formatting shared by the writers.
 *
 * @internal
 */
final class Format
{
    /** Amounts (BT-92, BT-106 ... BT-131): exactly two decimals (BR-DEC rules). */
    public static function amount(Decimal $value): string
    {
        return $value->toFixed(2);
    }

    /** Unit prices may carry more decimals than amounts; keep at least two. */
    public static function price(Decimal $value): string
    {
        return $value->toMinScale(2);
    }

    /** Quantities and percentages: shortest exact representation. */
    public static function number(Decimal $value): string
    {
        return $value->toString();
    }

    public static function percent(Decimal $value): string
    {
        return $value->toMinScale(2);
    }
}
