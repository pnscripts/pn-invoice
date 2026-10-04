<?php

declare(strict_types=1);

namespace PnScripts\Invoice\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PnScripts\Invoice\Decimal;

final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalisation(): iterable
    {
        yield 'integer' => ['100', '100'];
        yield 'trailing zeros' => ['100.500', '100.5'];
        yield 'leading zeros' => ['007.10', '7.1'];
        yield 'leading dot' => ['.5', '0.5'];
        yield 'negative zero' => ['-0.00', '0'];
        yield 'plus sign' => ['+3', '3'];
        yield 'negative' => ['-12.340', '-12.34'];
    }

    #[DataProvider('normalisation')]
    public function testNormalises(string $input, string $expected): void
    {
        self::assertSame($expected, Decimal::of($input)->toString());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rounding(): iterable
    {
        yield 'half up' => ['1.005', 2, '1.01'];
        yield 'below half' => ['1.004', 2, '1'];
        yield 'negative half away from zero' => ['-1.005', 2, '-1.01'];
        yield 'negative below half' => ['-1.004', 2, '-1'];
        yield 'to integer' => ['2.5', 0, '3'];
        yield 'many decimals' => ['243.998', 2, '244'];
    }

    #[DataProvider('rounding')]
    public function testRoundsHalfAwayFromZero(string $input, int $scale, string $expected): void
    {
        self::assertSame($expected, Decimal::of($input)->round($scale)->toString());
    }

    public function testArithmeticIsExact(): void
    {
        $value = Decimal::of('0.1')->add('0.2');

        self::assertTrue($value->equals('0.3'));
        self::assertSame('37.0368', Decimal::of('3')->mul('12.3456')->toString());
        self::assertSame('0.33333333333333333333', Decimal::of(1)->div(3)->toString());
        self::assertSame('-5', Decimal::of('5')->negate()->toString());
        self::assertSame('5', Decimal::of('-5')->abs()->toString());
    }

    public function testFormatting(): void
    {
        self::assertSame('10.00', Decimal::of('10')->toFixed(2));
        self::assertSame('10.01', Decimal::of('10.005')->toFixed(2));
        self::assertSame('12.3456', Decimal::of('12.3456')->toMinScale(2));
        self::assertSame('12.30', Decimal::of('12.3')->toMinScale(2));
        self::assertSame('3', Decimal::of('2.5')->toFixed(0));
    }

    public function testComparisons(): void
    {
        $value = Decimal::of('1.50');

        self::assertTrue($value->greaterThan('1.4'));
        self::assertTrue($value->lessThan(2));
        self::assertTrue($value->isPositive());
        self::assertFalse($value->isNegative());
        self::assertTrue(Decimal::zero()->isZero());
        self::assertSame(2, Decimal::of('-12.345')->round(2)->scale());
    }

    public function testTryParseReturnsNullForInvalidInput(): void
    {
        self::assertNull(Decimal::tryParse(null));
        self::assertNull(Decimal::tryParse('12,50'));
        self::assertNull(Decimal::tryParse('1e3'));
        self::assertNull(Decimal::tryParse(''));
        self::assertSame('12.5', Decimal::tryParse(' 12.50 ')?->toString());
    }

    public function testRejectsInvalidInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('abc');
    }

    public function testRejectsDivisionByZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of(1)->div(0);
    }
}
