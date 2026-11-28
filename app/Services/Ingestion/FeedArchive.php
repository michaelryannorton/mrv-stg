<?php

namespace App\Services\Ingestion;

use App\Models\Source;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The latest whole response of each feed, kept compressed in the app's private storage and replaced on
 * each run (Michael, 2026-11-27: keep everything the feeds send). It holds what never becomes an
 * event: past items, items beyond the window and calendar-level settings. Never public.
 *
 * A collector adds each response it reads; commit() is called only after the whole fetch worked, so a
 * failed run leaves the previous copy. A failure to write is reported and never stops ingestion.
 */
class FeedArchive
{
    /** @var array<string, string> response bodies by name */
    private array $bodies = [];

    public function add(string $name, string $body): void
    {
        $this->bodies[$name] = $body;
    }

    public static function directory(Source $source): string
    {
        return 'feeds/'.$source->id;
    }

    public function commit(Source $source): void
    {
        if ($this->bodies === []) {
            return;
        }

        try {
            $disk = Storage::disk('local');
            $kept = [];

            foreach ($this->bodies as $name => $body) {
                $path = self::directory($source).'/'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $name).'.gz';
                $disk->put($path, gzencode($body, 6));
                $kept[] = $path;
            }

            // A feed that now has fewer pages keeps no page of an older run.
            $disk->delete(array_values(array_diff($disk->files(self::directory($source)), $kept)));
        } catch (Throwable $e) {
            report($e);
        }

        $this->bodies = [];
    }
}
