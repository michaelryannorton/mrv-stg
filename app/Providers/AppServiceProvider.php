<?php

namespace App\Providers;

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
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => redirect()
            ->route('submit.create')
            ->with('error', $message)
            ->withHeaders($headers);

        RateLimiter::for('submissions', fn (Request $request) => [
            Limit::perHour(10)->by('hour:'.$request->ip())
                ->response($tooMany('You have submitted several events recently. Please try again in an hour.')),
            Limit::perDay(30)->by('day:'.$request->ip())
                ->response($tooMany('You have reached the daily limit for submissions. Please try again tomorrow.')),
        ]);

        // Looking up a link makes the server fetch that page, which is the costly part, so it has its
        // own limit; opening the empty form is never limited.
        RateLimiter::for('submission-lookups', fn (Request $request) => $request->filled('url')
            ? Limit::perMinute(6)->by('lookup:'.$request->ip())
                ->response($tooMany('Too many link lookups. Wait a minute, or fill in the form by hand.'))
            : Limit::none());
    }
}
