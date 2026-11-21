<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The daily email to administrators while event submissions are waiting for review
 * (command submissions:notify-pending). It carries counts and a link, not what visitors typed.
 */
class PendingSubmissionsDigest extends Notification
{
    public function __construct(
        public readonly int $count,
        public readonly Carbon $oldest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $noun = $this->count === 1 ? 'event submission is' : 'event submissions are';
        $waiting = $this->oldest->diffForHumans(['parts' => 1, 'syntax' => Carbon::DIFF_ABSOLUTE]);

        return (new MailMessage)
            ->subject($this->count === 1 ? '1 event submission waiting for review' : "{$this->count} event submissions waiting for review")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->count} {$noun} waiting for review. The oldest has been waiting {$waiting}.")
            ->action('Review submissions', route('admin.submissions.index'))
            ->line('This email is sent once a day, and only while at least one submission is pending.')
            ->salutation('Mojave River Valley');
    }
}
