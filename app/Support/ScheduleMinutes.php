<?php

namespace App\Support;

/**
 * The minutes at which the Laravel scheduler runs its commands (logs/2026 1122 1626 Plan E Stagger Cron Minutes Off The Half Hour.md).
 * At :00 and :30 the hourly jobs in api/cron/schedule.php and other sites' jobs start together, and the scheduler then could not start
 * its child process ("proc_open(): Fork failed: Resource temporarily unavailable"). Each copy therefore has its own minutes.
 */
final class ScheduleMinutes
{
    /** Production at :12 and :42, every other environment (staging, local, testing) at :17 and :47. */
    public static function ingestCron(string $environment): string
    {
        return $environment === 'production' ? '12,42 * * * *' : '17,47 * * * *';
    }

    /**
     * The other scheduled feeds, four times a day, at minutes nothing else here uses: production at
     * :27, every other environment at :52.
     */
    public static function feedsCron(string $environment): string
    {
        return ($environment === 'production' ? '27' : '52').' 1,7,13,19 * * *';
    }

    /** Looking up coordinates for new places: production at :18 and :48, elsewhere :23 and :53. */
    public static function placesCron(string $environment): string
    {
        return $environment === 'production' ? '18,48 * * * *' : '23,53 * * * *';
    }
}
