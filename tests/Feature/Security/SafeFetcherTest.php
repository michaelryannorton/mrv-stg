<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\EventExtractor;
use App\Support\SafeHttp\AddressPolicy;
use App\Support\SafeHttp\HostResolver;
use App\Support\SafeHttp\SafeFetcher;
use App\Support\SafeHttp\UnsafeUrlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The public "Submit an Event" form fetches a page for any address a visitor types. These tests keep
 * that from reaching the server's own machine or network (server-side request forgery).
 */
class SafeFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_IP = '93.184.216.34';

    /** Host names the fake resolver knows; anything else does not resolve. */
    private function resolving(array $map = []): void
    {
        $map += [
            'events.example' => [self::PUBLIC_IP],
            'other.example' => ['8.8.8.8'],
            'localhost' => ['127.0.0.1'],
            'metadata.example' => ['169.254.169.254'],
            'internal.example' => ['10.1.2.3'],
            'mixed.example' => [self::PUBLIC_IP, '10.0.0.1'],
            'v6.example' => ['2606:4700:4700::1111'],
            'v6private.example' => ['fd12:3456:789a::1'],
            // Names a lenient resolver might hand a public address; the numeric-host rule must refuse them anyway.
            '2130706433' => [self::PUBLIC_IP],
            '0x7f.0.0.1' => [self::PUBLIC_IP],
            '127.1' => [self::PUBLIC_IP],
            '0177.0.0.1' => [self::PUBLIC_IP],
        ];

        $this->app->instance(HostResolver::class, new class($map) extends HostResolver
        {
            public function __construct(private array $map) {}

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        });
    }

    private function fetcher(): SafeFetcher
    {
        return $this->app->make(SafeFetcher::class);
    }

    public static function blockedAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '127.0.0.1', '127.1.2.3', '0.0.0.0', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '169.254.169.254', '100.64.0.1', '100.127.255.255', '198.18.0.1', '192.0.2.1', '224.0.0.1', '255.255.255.255',
            '::', '::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1',
            '::ffff:127.0.0.1', '::ffff:8.8.8.8', '64:ff9b::7f00:1', '2002:7f00:1::',
        ]);
    }

    public static function publicAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '8.8.8.8', '1.1.1.1', '93.184.216.34', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1',
            '2606:4700:4700::1111', '2a00:1450:4001::200e',
        ]);
    }

    #[DataProvider('blockedAddresses')]
    public function test_private_loopback_and_reserved_addresses_are_not_public(string $ip): void
    {
        $this->assertFalse((new AddressPolicy)->isPublic($ip));
    }

    #[DataProvider('publicAddresses')]
    public function test_ordinary_internet_addresses_are_public(string $ip): void
    {
        $this->assertTrue((new AddressPolicy)->isPublic($ip));
    }

    public function test_something_that_is_not_an_ip_address_is_not_public(): void
    {
        $this->assertFalse((new AddressPolicy)->isPublic('not-an-ip'));
        $this->assertFalse((new AddressPolicy)->isPublic(''));
    }

    public static function refusedUrls(): array
    {
        return [
            'loopback ip' => ['http://127.0.0.1/'],
            'loopback ip with a path' => ['http://127.0.0.1/admin'],
            'localhost by name' => ['http://localhost/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv4-mapped ipv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'cloud metadata ip' => ['http://169.254.169.254/latest/meta-data/'],
            'a name that points at the metadata ip' => ['http://metadata.example/'],
            'a name that points at a private ip' => ['http://internal.example/'],
            'a name with one public and one private address' => ['http://mixed.example/'],
            'a name that points at a private ipv6 address' => ['http://v6private.example/'],
            'private 10.x' => ['http://10.0.0.1/'],
            'private 192.168.x' => ['http://192.168.0.1/'],
            'decimal ip' => ['http://2130706433/'],
            'hex ip' => ['http://0x7f.0.0.1/'],
            'short ip' => ['http://127.1/'],
            'octal ip' => ['http://0177.0.0.1/'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://events.example/'],
            'gopher' => ['gopher://events.example/'],
            'javascript' => ['javascript:alert(1)'],
            'credentials in the address' => ['http://user:secret@events.example/'],
            'a database port' => ['http://events.example:3306/'],
            'an alternate web port' => ['http://events.example:8080/'],
            'ssh port' => ['https://events.example:22/'],
            'no scheme' => ['//events.example/x'],
            'relative' => ['/events/1'],
            'a space in the address' => ['http://events.example/a b'],
            'a newline in the address' => ["http://events.example/a\nb"],
            'a host that does not resolve' => ['http://nonexistent.example/'],
            'a trailing dot host' => ['http://events.example./'],
            'a host starting with a dash' => ['http://-bad.example/'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function test_unsafe_addresses_are_refused_before_any_request(string $url): void
    {
        $this->resolving();
        Http::fake();

        try {
            $this->fetcher()->get($url);
            $this->fail("Fetched {$url}");
        } catch (UnsafeUrlException $e) {
            $this->assertStringNotContainsString('events.example', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public static function acceptedUrls(): array
    {
        return [
            'https' => ['https://events.example/events/1?x=1'],
            'http' => ['http://events.example'],
            'explicit https port' => ['https://events.example:443/x'],
            'explicit http port' => ['http://events.example:80/'],
            'a public ip' => ['http://8.8.8.8/x'],
            'a public ipv6 ip' => ['http://[2606:4700:4700::1111]/'],
            'a name with a public ipv6 address' => ['https://v6.example/'],
            'upper case' => ['HTTPS://Events.Example/Path'],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function test_ordinary_public_pages_are_fetched(string $url): void
    {
        $this->resolving();
        Http::fake(['*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame(200, $this->fetcher()->get($url)->status());
        Http::assertSentCount(1);
    }

    public function test_the_connection_is_pinned_to_the_address_that_was_checked(): void
    {
        $this->resolving();

        $target = $this->fetcher()->target('https://events.example/x');
        $this->assertSame(self::PUBLIC_IP, $target['ip']);
        $this->assertSame(
            ['events.example:443:'.self::PUBLIC_IP],
            $this->fetcher()->options($target)['curl'][CURLOPT_RESOLVE],
        );

        $v6 = $this->fetcher()->target('http://v6.example/x');
        $this->assertSame(['v6.example:80:[2606:4700:4700::1111]'], $this->fetcher()->options($v6)['curl'][CURLOPT_RESOLVE]);

        $literal = $this->fetcher()->target('http://8.8.8.8/x');
        $this->assertArrayNotHasKey('curl', $this->fetcher()->options($literal));
    }

    public function test_a_redirect_to_a_private_address_is_refused_before_it_is_followed(): void
    {
        $this->resolving();
        Http::fake(['events.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $this->expectException(UnsafeUrlException::class);

        try {
            $this->fetcher()->get('https://events.example/start');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_redirect_to_a_name_that_points_inside_is_refused(): void
    {
        $this->resolving();
        Http::fake(['events.example/*' => Http::response('', 301, ['Location' => 'http://internal.example/'])]);

        $this->expectException(UnsafeUrlException::class);
        $this->fetcher()->get('https://events.example/start');
    }

    public function test_a_redirect_to_another_scheme_is_refused(): void
    {
        $this->resolving();
        Http::fake(['events.example/*' => Http::response('', 302, ['Location' => 'file:///etc/passwd'])]);

        $this->expectException(UnsafeUrlException::class);
        $this->fetcher()->get('https://events.example/start');
    }

    public function test_public_redirects_are_followed_including_relative_ones(): void
    {
        $this->resolving();
        Http::fake([
            'events.example/a/start' => Http::response('', 302, ['Location' => 'next']),
            'events.example/a/next' => Http::response('', 302, ['Location' => '/b/final']),
            'events.example/b/final' => Http::response('', 302, ['Location' => '//other.example/done']),
            'other.example/done' => Http::response('<html>ok</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->fetcher()->get('https://events.example/a/start');

        $this->assertSame('<html>ok</html>', $response->body());
        Http::assertSentCount(4);
    }

    public function test_a_redirect_loop_stops(): void
    {
        $this->resolving();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://events.example/again'])]);

        try {
            $this->fetcher()->get('https://events.example/start');
            $this->fail('A redirect loop was followed');
        } catch (UnsafeUrlException) {
            Http::assertSentCount(SafeFetcher::MAX_REDIRECTS + 1);
        }
    }

    public function test_a_redirect_without_a_location_is_refused(): void
    {
        $this->resolving();
        Http::fake(['*' => Http::response('', 302)]);

        $this->expectException(UnsafeUrlException::class);
        $this->fetcher()->get('https://events.example/start');
    }

    public function test_a_response_body_larger_than_the_cap_cannot_be_written(): void
    {
        $this->resolving();
        $sink = $this->fetcher()->options($this->fetcher()->target('https://events.example/x'))['sink'];

        $chunk = str_repeat('a', 1024 * 1024);
        $this->assertSame(1024 * 1024, fwrite($sink, $chunk));
        $this->assertSame(1024 * 1024, fwrite($sink, $chunk));
        $this->assertFalse(@fwrite($sink, 'b'));
    }

    public function test_extract_from_url_still_reads_a_public_event_page(): void
    {
        $this->resolving();
        $html = '<html><head><script type="application/ld+json">{"@type":"Event","name":"Block Party","startDate":"2026-11-14T11:00:00-08:00"}</script></head></html>';
        Http::fake(['events.example/*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

        $this->assertSame('Block Party', EventExtractor::extractFromUrl('https://events.example/party')['title'] ?? null);
    }

    public function test_extract_from_url_returns_nothing_and_sends_nothing_for_an_internal_address(): void
    {
        $this->resolving();
        Http::fake();
        Log::spy();

        $this->assertSame([], EventExtractor::extractFromUrl('http://169.254.169.254/latest/meta-data/'));

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message) => ! str_contains($message, '169.254'));
    }

    public function test_the_public_form_does_not_fetch_internal_addresses(): void
    {
        $this->resolving();
        Http::fake();

        $this->get(route('submit.create', ['url' => 'http://localhost/server-status']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('submit/create')->where('prefilled', []));

        Http::assertNothingSent();
    }

    public function test_the_admin_clipper_uses_the_same_guard(): void
    {
        $this->resolving();
        Http::fake();
        $admin = User::factory()->create()->assignRole('administrator');

        $this->actingAs($admin)->get(route('admin.clip.create', ['url' => 'http://10.1.2.3/']))->assertOk();

        Http::assertNothingSent();
    }
}
