<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Only Cloudflare's ranges may report a visitor's address (App\Support\CloudflareProxies). A request
 * from any other address keeps its own address whatever headers it sends, so rate limits cannot be
 * dodged by a forged X-Forwarded-For.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__test/client', fn (Request $request) => [
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    }

    private function requestFrom(string $remote, array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])->getJson('/__test/client', $headers);
    }

    public function test_a_cloudflare_address_passes_on_the_visitor_address_and_scheme(): void
    {
        $this->requestFrom('173.245.48.10', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertExactJson(['ip' => '203.0.113.9', 'secure' => true]);

        $this->requestFrom('2606:4700::1', ['X-Forwarded-For' => '203.0.113.10'])
            ->assertJsonPath('ip', '203.0.113.10');
    }

    public function test_any_other_address_cannot_set_its_own(): void
    {
        $this->requestFrom('198.51.100.7', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->assertExactJson(['ip' => '198.51.100.7', 'secure' => false]);
    }

    public function test_a_forged_address_in_front_of_cloudflare_is_not_believed(): void
    {
        // A visitor can send their own X-Forwarded-For; Cloudflare appends the real address after it.
        $this->requestFrom('173.245.48.10', ['X-Forwarded-For' => '10.0.0.1, 203.0.113.9'])
            ->assertJsonPath('ip', '203.0.113.9');
    }

    public function test_a_direct_request_without_headers_keeps_its_address(): void
    {
        $this->requestFrom('198.51.100.7')->assertJsonPath('ip', '198.51.100.7');
    }
}
