<?php

namespace App\Support\Weather;

/**
 * PHP's round() as it was up to PHP 8.3, which the legacy API (PHP 8.1) uses. PHP 8.4 changed round() so that a value that is a hair
 * under a half in binary (157.12 - 249.07 = -91.94999999999999) rounds the way its binary value says (-91.9); up to 8.3 the value was
 * first rounded to 15 significant digits, which makes it -91.95 and rounds it to -92. The summaries must give the legacy answers,
 * and this app runs on PHP 8.4 or later, so they call this instead. A port of _php_math_round (ext/standard/math.c, PHP 8.1) for
 * round half away from zero; tests/Unit/Weather/LegacyRoundTest.php pins it to values PHP 8.3 produced.
 */
final class LegacyRound
{
    public static function round(float $value, int $places = 0): float
    {
        if (! is_finite($value) || $value == 0.0) {
            return $value;
        }

        $precisionPlaces = 14 - (int) floor(log10(abs($value)));
        $f1 = self::pow10(abs($places));

        // If the precision floating point arithmetic guarantees is higher than the requested places, but small enough that a
        // non-zero value is returned, round to that precision first.
        if ($precisionPlaces > $places && $precisionPlaces - 15 < $places) {
            $usePrecision = $precisionPlaces < -60 ? -60 : $precisionPlaces;

            // preround the result (the value is now something times 1e14, never larger than 1e15)
            $tmp = self::helper(self::basic($value, $usePrecision));

            $usePrecision = max(-60, $places - $usePrecision);
            // because $places < $precisionPlaces, $usePrecision is negative: scale back down
            $tmp = $tmp / self::pow10(abs($usePrecision));
        } else {
            // adjust the value
            $tmp = $places >= 0 ? $value * $f1 : $value / $f1;
            // beyond our precision, so rounding it is pointless
            if (abs($tmp) >= 1e15) {
                return $value;
            }
        }

        $tmp = self::helper($tmp);

        return $places > 0 ? $tmp / $f1 : $tmp * $f1;
    }

    private static function basic(float $value, int $places): float
    {
        $f1 = self::pow10(abs($places));

        return $places >= 0 ? $value * $f1 : $value / $f1;
    }

    private static function helper(float $value): float
    {
        return $value >= 0.0 ? floor($value + 0.5) : ceil($value - 0.5);
    }

    private static function pow10(int $power): float
    {
        return $power < 0 || $power > 22 ? 10.0 ** $power : (float) ('1e'.$power);
    }
}
