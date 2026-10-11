<?php

namespace App\Support\SafeHttp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a page for a visitor-supplied address without letting the visitor aim the server at its
 * own machine or network (server-side request forgery). For every request, including every redirect:
 *
 * - only http and https, only ports 80 and 443, no user name or password in the address;
 * - the host name is resolved here and every address it points to must be public (AddressPolicy);
 *   address forms that other software reads differently (decimal or hex numbers, 127.1) are refused;
 * - the connection is pinned to the address that was checked (curl's resolve option), so a name that
 *   changes its answer between the check and the connection cannot reach a private address;
 * - redirects are followed by hand, at most MAX_REDIRECTS, each one checked the same way;
 * - the body is capped at MAX_BYTES and the whole request is time limited.
 *
 * Anything refused throws UnsafeUrlException (whose message never contains the address).
 */
class SafeFetcher
{
    public const MAX_REDIRECTS = 3;

    public const MAX_BYTES = 2 * 1024 * 1024;

    private const PORTS = [80, 443];

    public function __construct(private HostResolver $resolver, private AddressPolicy $policy) {}

    public function get(string $url, array $headers = []): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->target($url);

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions($this->options($target))
                ->get($target['url']);

            if (! $response->redirect()) {
                return $response;
            }

            $location = trim($response->header('Location'));

            if ($location === '') {
                throw new UnsafeUrlException('Redirect without a location');
            }

            $url = $this->absolute($target['url'], $location);
        }

        throw new UnsafeUrlException('Too many redirects');
    }

    /** @return array{url: string, host: string, port: int, ip: ?string} ip is null for an address given as a literal IP. */
    public function target(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new UnsafeUrlException('Control or space characters in the address');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('Not an absolute address');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Scheme not allowed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('Credentials in the address');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($port, self::PORTS, true)) {
            throw new UnsafeUrlException('Port not allowed');
        }

        $host = strtolower($parts['host']);
        $literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        $isIp = filter_var($literal, FILTER_VALIDATE_IP) !== false;

        if (! $isIp) {
            $host = $this->asciiHost($host);
            $ips = $this->hostIps($host);
        } else {
            $ips = [$literal];
        }

        foreach ($ips as $ip) {
            if (! $this->policy->isPublic($ip)) {
                throw new UnsafeUrlException('Address is not public');
            }
        }

        $rebuilt = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');

        return ['url' => $rebuilt, 'host' => $host, 'port' => $port, 'ip' => $isIp ? null : $ips[0]];
    }

    private function asciiHost(string $host): string
    {
        if (preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if ($ascii === false) {
                throw new UnsafeUrlException('Host name not valid');
            }

            $host = strtolower($ascii);
        }

        // Numbers in decimal, hex or short form ("2130706433", "0x7f.1", "127.1") are read as IP
        // addresses by some software and as names by others; none of them is a real site.
        if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+))*$/', $host)) {
            throw new UnsafeUrlException('Numeric host');
        }

        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            throw new UnsafeUrlException('Host name not valid');
        }

        return $host;
    }

    /** @return non-empty-list<string> */
    private function hostIps(string $host): array
    {
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new UnsafeUrlException('Host did not resolve');
        }

        return $ips;
    }

    /** Guzzle options for one request: pinned address, capped body. */
    public function options(array $target): array
    {
        self::registerFilter();

        $sink = fopen('php://temp', 'w+');
        stream_filter_append($sink, 'safehttp.limit', STREAM_FILTER_WRITE, ['max' => self::MAX_BYTES]);

        $options = ['sink' => $sink];

        if ($target['ip'] !== null) {
            $address = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$address}"]];
        }

        return $options;
    }

    private static function registerFilter(): void
    {
        static $registered = false;

        if (! $registered) {
            stream_filter_register('safehttp.limit', SizeLimitFilter::class);
            $registered = true;
        }
    }

    /** Resolves a Location header against the address that returned it. */
    private function absolute(string $base, string $location): string
    {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'];

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $origin = $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, strrpos($path, '/') + 1).$location;
    }
}
