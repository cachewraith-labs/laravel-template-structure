<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit tests for the one place decimals and minor units meet.
 *
 * No database and no HTTP — Money is a pure conversion. The cases that matter
 * are the ones where (int) round($value * 100) gets it wrong: 1.15 is not
 * representable in binary floating point, and a price that is one cent out in
 * production is a bug nobody can reproduce from the logs.
 *
 * The rejected inputs matter just as much as the accepted ones. Money returns
 * null rather than guessing, so the form request's rules reject the value
 * instead of a silent 0 reaching the database (OWASP A10: fail closed).
 */
final class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function inputs(): array
    {
        return [
            'integer passes through' => [1999, 1999],
            'whole number' => ['20', 2000],
            'two decimal places' => ['19.99', 1999],
            'one decimal place' => ['19.9', 1990],
            'the float-rounding trap' => ['1.15', 115],
            'zero' => ['0', 0],
            'zero with decimals' => ['0.00', 0],
            'padded whitespace' => ['  4.20  ', 420],
            'explicit plus' => ['+4.20', 420],
            'negative' => ['-4.20', -420],
            'negative zero' => ['-0.00', 0],

            // Everything below must be rejected, not coerced. A silent 0 in
            // the price column is worse than a validation error.
            'empty string' => ['', null],
            'thousands separator' => ['1,999.00', null],
            'currency symbol' => ['$19.99', null],
            'three decimal places' => ['19.999', null],
            'exponent notation' => ['1e3', null],
            'hexadecimal' => ['0x10', null],
            'letters' => ['nineteen', null],
            'null' => [null, null],
            'array' => [[], null],
            'boolean' => [true, null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_it_parses_only_plain_decimals(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, Money::toMinorUnits($input));
    }

    public function test_it_renders_minor_units_as_a_decimal(): void
    {
        $this->assertSame('19.99', Money::toDecimal(1999));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('0.00', Money::toDecimal(0));
        $this->assertSame('0.00', Money::toDecimal(null));
    }

    public function test_a_round_trip_is_lossless(): void
    {
        foreach (['0.00', '0.01', '1.15', '19.99', '8.11', '999999.99'] as $decimal) {
            $this->assertSame($decimal, Money::toDecimal(Money::toMinorUnits($decimal)));
        }
    }
}
