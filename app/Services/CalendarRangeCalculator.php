<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Computes the query range and grid boundaries for the public calendar's day/week/month views, all
 * anchored to a single date. The region's operating timezone (America/Los_Angeles) decides what
 * "today"/week/month boundaries mean, matching EventController's existing `when` filter — the
 * region has one civic calendar regardless of an individual event's own timezone.
 */
class CalendarRangeCalculator
{
    /**
     * @return array{rangeStart: CarbonImmutable, rangeEnd: CarbonImmutable, gridStart: CarbonImmutable, gridEnd: CarbonImmutable, prevDate: string, nextDate: string}
     */
    public static function forView(string $view, CarbonImmutable $anchor): array
    {
        return match ($view) {
            'day' => self::day($anchor),
            'week' => self::week($anchor),
            'month' => self::month($anchor),
            default => throw new \InvalidArgumentException("Unsupported calendar view: {$view}"),
        };
    }

    private static function day(CarbonImmutable $anchor): array
    {
        $start = $anchor->startOfDay();

        return [
            'rangeStart' => $start,
            'rangeEnd' => $start->addDay(),
            'gridStart' => $start,
            'gridEnd' => $start,
            'prevDate' => $anchor->subDay()->toDateString(),
            'nextDate' => $anchor->addDay()->toDateString(),
        ];
    }

    private static function week(CarbonImmutable $anchor): array
    {
        $start = $anchor->startOfWeek();
        $end = $start->addWeek();

        return [
            'rangeStart' => $start,
            'rangeEnd' => $end,
            'gridStart' => $start,
            'gridEnd' => $end->subDay(),
            'prevDate' => $anchor->subWeek()->toDateString(),
            'nextDate' => $anchor->addWeek()->toDateString(),
        ];
    }

    private static function month(CarbonImmutable $anchor): array
    {
        // Carbon's addMonth()/subMonth() preserve the day-of-month, which drifts (or clamps
        // unpredictably around month-end) when repeatedly paging from a late-month anchor — e.g.
        // the 31st + addMonth() through a 30-day month. Anchoring prev/next to the 1st instead
        // means every "next"/"previous" click lands on a clean, predictable month regardless of
        // which day was originally being viewed.
        $monthStart = $anchor->startOfMonth();
        $gridStart = $monthStart->startOfWeek();
        $gridEnd = $anchor->endOfMonth()->endOfWeek();

        return [
            'rangeStart' => $gridStart,
            'rangeEnd' => $gridEnd->addDay(),
            'gridStart' => $gridStart,
            'gridEnd' => $gridEnd,
            'prevDate' => $monthStart->subMonth()->toDateString(),
            'nextDate' => $monthStart->addMonth()->toDateString(),
        ];
    }
}
