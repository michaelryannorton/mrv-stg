<?php

namespace Tests\Feature\Invitations;

use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** The invitation as a real, fully rendered message (array mailer), not a faked notification. */
class InvitationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->all();
    }

    private function send(string $token = 'abc123token'): Email
    {
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@mojaverivervalley.org',
            'mail.from.name' => 'Mojave River Valley',
            'app.name' => 'Mojave River Valley Community',
        ]);
        $user = User::factory()->create(['name' => 'Casey Curator', 'email' => 'casey@example.test']);

        $user->notify(new UserInvitation($token, 'Michael Norton', 'curator'));

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);

        return $messages[0];
    }

    public function test_the_message_is_addressed_from_the_no_reply_sender_to_the_invitee(): void
    {
        $email = $this->send();

        $this->assertSame('casey@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('no-reply@mojaverivervalley.org', $email->getFrom()[0]->getAddress());
        $this->assertSame('Mojave River Valley', $email->getFrom()[0]->getName());
        $this->assertSame('You are invited to Mojave River Valley Community', $email->getSubject());
    }

    public function test_the_body_names_the_inviter_the_role_the_link_and_the_expiry(): void
    {
        $email = $this->send('tok-9f2');
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Hello Casey Curator,', $body);
            $this->assertStringContainsString('Michael Norton has invited you to help curate community events as a curator.', $body);
            $this->assertStringContainsString('/invitation/tok-9f2?email=casey%40example.test', $body);
            $this->assertStringContainsString('expires on '.now()->addDays(7)->format('F j, Y'), $body);
            $this->assertStringContainsString('If you were not expecting this invitation', $body);
        }
        $this->assertStringContainsString('Set your password', $html);
    }

    public function test_the_message_carries_the_application_name_and_never_a_password(): void
    {
        $email = $this->send();

        $this->assertStringContainsString('Mojave River Valley', $email->getHtmlBody());
        $this->assertStringNotContainsString('Laravel', strip_tags($email->getHtmlBody()));
        // The email only sends the invitee to choose a password; it never contains one.
        $this->assertStringNotContainsString('temporary password', strtolower($email->getTextBody()));
        $this->assertStringNotContainsString('your password is', strtolower($email->getTextBody()));
    }

    public function test_the_invitation_link_in_the_message_uses_the_application_url(): void
    {
        config(['app.url' => 'https://staging.example.test']);
        url()->forceRootUrl('https://staging.example.test');
        url()->forceScheme('https'); // on staging the request itself arrives over https, so links are https

        $email = $this->send();

        $this->assertStringContainsString('https://staging.example.test/invitation/', $email->getTextBody());
    }
}
