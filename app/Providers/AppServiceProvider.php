<?php

namespace App\Providers;

use App\Support\WaitPhrase;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePublicSubmissionLimits();
    }

    /**
     * /submit-event is open to anonymous visitors and writes to the database, so each address gets a
     * small allowance. The two limits under one name need distinct key prefixes, or they would share
     * a counter. The key is the request IP: behind a proxy such as Cloudflare it is only the visitor's
     * address once the proxy is trusted (not configured; see the Slice 1 plan, section 13), otherwise
     * every visitor would share the proxy's allowance.
     */
    private function configurePublicSubmissionLimits(): void
    {
        // The message says what happened and when to come back, but not the numbers behind the limit.
        // $message gets the wait time as a phrase such as "12 minutes".
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => redirect()
            ->route('submit.create')
            ->with('error', sprintf($message, WaitPhrase::for((int) ($headers['Retry-After'] ?? 60))))
            ->withHeaders($headers);

        $notice = 'The limit keeps spam out of the review queue and applies to everyone.';

        // The data the pages read: a page view makes a handful of requests; this is far above that and below a scraper.
        RateLimiter::for('weather-data', fn (Request $request) => Limit::perMinute(240)->by($request->ip()));

        RateLimiter::for('submissions', fn (Request $request) => [
            Limit::perHour(10)->by('hour:'.$request->ip())
                ->response($tooMany("You've reached the limit for event submissions from your connection for now. What you entered is still in the form. Please try again in about %s. {$notice}")),
            Limit::perDay(30)->by('day:'.$request->ip())
                ->response($tooMany("You've reached today's limit for event submissions from your connection. What you entered is still in the form. Please try again in about %s. {$notice}")),
        ]);

        // Looking up a link makes the server fetch that page, which is the costly part, so it has its
        // own limit; opening the empty form is never limited.
        RateLimiter::for('submission-lookups', fn (Request $request) => $request->filled('url')
            ? Limit::perMinute(6)->by('lookup:'.$request->ip())
                ->response($tooMany('Too many link lookups in a short time. You can fill in the form by hand now, or try the link again in about %s. The limit applies to everyone.'))
            : Limit::none());
    }
}
