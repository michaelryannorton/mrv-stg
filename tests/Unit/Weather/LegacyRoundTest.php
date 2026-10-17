<?php

namespace Tests\Unit\Weather;

use App\Support\Weather\LegacyRound;
use PHPUnit\Framework\TestCase;

/** Values PHP 8.3's round() produced (checked against 2.4 million values on PHP 8.3 when this class was written); PHP 8.4 differs on some. */
class LegacyRoundTest extends TestCase
{
    public function test_it_rounds_a_value_a_hair_under_a_half_the_way_php_8_3_did(): void
    {
        $this->assertSame(-92.0, LegacyRound::round(157.12 - 249.07, 1));
        $this->assertSame(-92.0, LegacyRound::round(-91.94999999999999, 1));
        $this->assertSame(17.91, LegacyRound::round(17.904999999999998, 2));
        $this->assertSame(1.01, LegacyRound::round(1.0049999999999999, 2));
        $this->assertSame(5.06, LegacyRound::round(5.055, 2));
        $this->assertSame(1.96, LegacyRound::round(1.955, 2));
        $this->assertSame(0.29, LegacyRound::round(0.285, 2));
    }

    public function test_it_rounds_half_away_from_zero_and_keeps_exact_values(): void
    {
        $this->assertSame(3.0, LegacyRound::round(2.5));
        $this->assertSame(-3.0, LegacyRound::round(-2.5));
        $this->assertSame(1.5, LegacyRound::round(1.5, 1));
        $this->assertSame(0.0, LegacyRound::round(0.0, 2));
        $this->assertSame(12.35, LegacyRound::round(12.345, 2));
        $this->assertSame(-0.5, LegacyRound::round(-0.5, 1));
        $this->assertSame(1234.0, LegacyRound::round(1234.4));
    }

    public function test_it_passes_through_values_it_cannot_round(): void
    {
        $this->assertNan(LegacyRound::round(NAN));
        $this->assertSame(INF, LegacyRound::round(INF, 2));
        $this->assertSame(1.0e20, LegacyRound::round(1.0e20, 2));
    }
}
