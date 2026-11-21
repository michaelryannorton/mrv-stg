<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Two cheap checks that keep scripts out of the public "Submit an event" form without asking the
 * visitor to do anything and without a third-party service:
 *
 * - a hidden field people never see; a script that fills in every field fills this one too;
 * - a minimum time between opening the form and sending it, measured from a timestamp the server
 *   put in the page (encrypted, so a script cannot forge an older one).
 *
 * Neither check replaces the per-address limits in AppServiceProvider; they sit in front of them.
 */
final class SubmissionFormGuard
{
    /** Seconds a person needs at least to fill the form in. */
    public const MIN_SECONDS = 4;

    public const OK = 'ok';

    /** The hidden field was filled in: drop the submission without saying so. */
    public const BOT = 'bot';

    /** The timestamp is missing, not readable or from the future: ask for a reload. */
    public const INVALID = 'invalid';

    public const TOO_FAST = 'too_fast';

    /** The value put in the page when the form is opened. $at is for tests. */
    public static function issue(?Carbon $at = null): string
    {
        return Crypt::encryptString((string) ($at ?? now())->getTimestamp());
    }

    public static function check(?string $honeypot, ?string $token): string
    {
        if ($honeypot !== null && trim($honeypot) !== '') {
            return self::BOT;
        }

        if ($token === null || $token === '') {
            return self::INVALID;
        }

        try {
            $issuedAt = (int) Crypt::decryptString($token);
        } catch (DecryptException) {
            return self::INVALID;
        }

        $elapsed = now()->getTimestamp() - $issuedAt;

        if ($elapsed < 0) {
            return self::INVALID;
        }

        return $elapsed < self::MIN_SECONDS ? self::TOO_FAST : self::OK;
    }
}
