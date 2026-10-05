<?php

declare(strict_types=1);

namespace Pnscripts\Invoice;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable arbitrary-precision decimal number backed by ext-bcmath.
 *
 * Used for every amount, quantity, price and rate in the model, so no value
 * ever passes through a binary float.
 */
final readonly class Decimal implements Stringable
{
    /** Internal working scale for intermediate results. */
    private const int WORK_SCALE = 20;

    /** @var numeric-string */
    private string $value;

    private function __construct(string $value)
    {
        $this->value = self::normalise($value);
    }

    public static function of(self|int|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $string = is_int($value) ? (string) $value : trim($value);

        if (!self::isValid($string)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid decimal number.', $string));
        }

        return new self($string);
    }

    /**
     * Parses an XML decimal lexical value; returns null when the value is absent or not a decimal.
     */
    public static function tryParse(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return self::isValid($value) ? new self($value) : null;
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) === 1;
    }

    public function add(self|int|string $other): self
    {
        return new self(bcadd($this->value, self::of($other)->value, self::WORK_SCALE));
    }

    public function sub(self|int|string $other): self
    {
        return new self(bcsub($this->value, self::of($other)->value, self::WORK_SCALE));
    }

    public function mul(self|int|string $other): self
    {
        return new self(bcmul($this->value, self::of($other)->value, self::WORK_SCALE));
    }

    public function div(self|int|string $other): self
    {
        $divisor = self::of($other);

        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return new self(bcdiv($this->value, $divisor->value, self::WORK_SCALE));
    }

    /**
     * Rounds half away from zero (commercial rounding) to the given number of decimals.
     */
    public function round(int $scale = 2): self
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Scale must not be negative.');
        }

        $half = self::numeric('0.' . str_repeat('0', $scale) . '5');
        $adjusted = $this->isNegative()
            ? bcsub($this->value, $half, $scale + 1)
            : bcadd($this->value, $half, $scale + 1);

        // bcadd with a scale truncates; truncate the adjusted value to the target scale.
        return new self(bcadd($adjusted, '0', $scale));
    }

    public function abs(): self
    {
        return $this->isNegative() ? new self(substr($this->value, 1)) : $this;
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return $this->isNegative() ? new self(substr($this->value, 1)) : new self('-' . $this->value);
    }

    public function compare(self|int|string $other): int
    {
        return bccomp($this->value, self::of($other)->value, self::WORK_SCALE);
    }

    public function equals(self|int|string $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public function isPositive(): bool
    {
        return !$this->isZero() && !$this->isNegative();
    }

    public function greaterThan(self|int|string $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self|int|string $other): bool
    {
        return $this->compare($other) < 0;
    }

    /**
     * Number of significant decimals (trailing zeros removed).
     */
    public function scale(): int
    {
        $dot = strpos($this->value, '.');

        return $dot === false ? 0 : strlen($this->value) - $dot - 1;
    }

    /**
     * Formats with a fixed number of decimals (rounding half away from zero when needed).
     */
    public function toFixed(int $scale): string
    {
        $rounded = $this->round($scale)->value;

        if ($scale === 0) {
            return $rounded;
        }

        $dot = strpos($rounded, '.');
        if ($dot === false) {
            return $rounded . '.' . str_repeat('0', $scale);
        }

        return $rounded . str_repeat('0', $scale - (strlen($rounded) - $dot - 1));
    }

    /**
     * Formats with at least $minScale decimals and no trailing zeros beyond that.
     */
    public function toMinScale(int $minScale): string
    {
        return $this->scale() >= $minScale ? $this->value : $this->toFixed($minScale);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string $value): string
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid decimal number.', $value));
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    private static function normalise(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        if (str_starts_with($value, '.')) {
            $value = '0' . $value;
        }

        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        $value = ltrim($value, '0');
        if ($value === '' || str_starts_with($value, '.')) {
            $value = '0' . $value;
        }

        if ($value === '0') {
            return '0';
        }

        return self::numeric(($negative ? '-' : '') . $value);
    }
}
