<?php

namespace App\Console\Commands;

use App\Models\PublicSubmission;
use App\Models\User;
use App\Notifications\PendingSubmissionsDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Emails every administrator once when at least one public event submission is pending, and sends
 * nothing when none are. Scheduled daily at 08:00 Pacific in routes/console.php (Michael, 2026-11-20,
 * "D4 daily"). Reads only; it changes no submission.
 */
class NotifyPendingSubmissions extends Command
{
    protected $signature = 'submissions:notify-pending';

    protected $description = 'Email the administrators if public event submissions are waiting for review';

    public function handle(): int
    {
        $pending = PublicSubmission::query()->where('status', 'pending');
        $count = (clone $pending)->count();

        if ($count === 0) {
            $this->info('No submissions pending; no email sent.');

            return self::SUCCESS;
        }

        $oldest = (clone $pending)->oldest('created_at')->firstOrFail()->created_at;
        $administrators = User::role('administrator')->whereNotNull('email')->get();

        if ($administrators->isEmpty()) {
            $this->warn("{$count} pending, but there is no administrator to email.");

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($administrators as $administrator) {
            try {
                $administrator->notify(new PendingSubmissionsDigest($count, $oldest));
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Pending-submissions email failed for user '.$administrator->id.': '.$e->getMessage());
            }
        }

        $sent = $administrators->count() - $failed;
        $this->info("{$count} pending; email sent to {$sent} of {$administrators->count()} administrators.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
