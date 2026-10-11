<?php

namespace Tests\Unit;

use App\Support\WaitPhrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WaitPhraseTest extends TestCase
{
    public static function waits(): array
    {
        return [
            'none' => [0, 'a minute'],
            'half a minute' => [30, 'a minute'],
            'exactly a minute' => [60, 'a minute'],
            'just over a minute' => [61, '2 minutes'],
            'rounds up to the minute' => [301, '6 minutes'],
            'fifty-five minutes' => [3300, '55 minutes'],
            'an hour' => [3600, '1 hour'],
            'just under two hours' => [6000, '2 hours'],
            'most of a day' => [75600, '21 hours'],
            'a full day' => [86400, '24 hours'],
        ];
    }

    #[DataProvider('waits')]
    public function test_it_gives_a_rough_human_wait(int $seconds, string $expected): void
    {
        $this->assertSame($expected, WaitPhrase::for($seconds));
    }
}
