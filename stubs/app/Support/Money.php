<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Conversion between the minor units the database stores and the decimal a
 * person types or reads.
 *
 * Prices are stored as integer minor units (see the items migration): a float
 * column accumulates rounding error, and "19.99" is not representable in
 * binary floating point. That decision is only safe if every crossing of the
 * boundary goes through one place — hence this class rather than a
 * number_format() sprinkled through the Blade templates and a
 * (int) round($x * 100) sprinkled through the form requests. Those two
 * conversions must agree, and the way to make them agree is to have one of
 * each.
 *
 * Deliberately not a value object: the domain here has a single currency and
 * no arithmetic. Two static conversions are the simplest construct that works;
 * promote this to a real Money value object the day the application starts
 * adding prices together or handling more than one currency.
 *
 * The API resources are not routed through here on purpose — V1 and V2 are
 * released contracts, and this class is part of the web scaffold. Retrofitting
 * a frozen version to use it would be a change to a version that is supposed
 * to be frozen.
 */
final class Money
{
    /**
     * Parse a human-typed decimal into integer minor units.
     *
     * Returns null for anything that is not a plain decimal, so the caller's
     * validation rules reject the input rather than a silent 0 reaching the
     * database (OWASP A10: fail closed). Parsing is done on the string, never
     * via (int) round($value * 100) — 1.15 * 100 is 114.99999999999999.
     */
    public static function toMinorUnits(mixed $input): ?int
    {
        if (is_int($input)) {
            return $input;
        }

        if (! is_string($input) && ! is_float($input)) {
            return null;
        }

        $value = trim((string) $input);

        // Bounded and anchored: no thousands separators, no exponent, at most
        // two decimal places, at most twelve digits before the point.
        if (preg_match('/^(?<sign>[+-]?)(?<whole>\d{1,12})(?:\.(?<fraction>\d{1,2}))?$/', $value, $m) !== 1) {
            return null;
        }

        $fraction = str_pad($m['fraction'] ?? '', 2, '0');
        $minor = (int) $m['whole'] * 100 + (int) $fraction;

        return $m['sign'] === '-' ? -$minor : $minor;
    }

    /**
     * Format integer minor units as a decimal string, for display only.
     */
    public static function toDecimal(?int $minor): string
    {
        return number_format(((int) $minor) / 100, 2, '.', '');
    }

    /**
     * The same decimal with the configured currency appended, for a label.
     */
    public static function format(?int $minor, ?string $currency = null): string
    {
        $currency ??= (string) config('cachewraith-template.currency', 'USD');

        return self::toDecimal($minor).' '.$currency;
    }
}
