<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email (Phase 2 slice 1c). Contains the one-time link and no password. Sent
 * synchronously: it is one transactional message per administrator action, not a bulk send.
 */
class UserInvitation extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $inviterName,
        public readonly string $role,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $expiresAt = now()->addMinutes((int) config('auth.passwords.invitations.expire'));
        $url = route('invitation.show', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)
            ->subject('You are invited to Mojave River Valley Community')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->inviterName} has invited you to help curate community events as a {$this->role}.")
            ->action('Set your password', $url)
            ->line('This link can be used once and expires on '.$expiresAt->format('F j, Y').'.')
            ->line('If you were not expecting this invitation, you can ignore this email.')
            ->salutation('Mojave River Valley');
    }
}
