<?php

namespace App\Support\SafeHttp;

/**
 * Which IP addresses count as the public internet. Everything else (loopback, private networks,
 * link-local including cloud metadata services, carrier-grade NAT, documentation and multicast
 * ranges, and IPv6 forms that can embed an IPv4 address) is refused, so a visitor-supplied link
 * cannot make the server talk to its own machine or its network. The list is explicit rather than
 * relying on PHP's filter flags, which do not cover every one of these ranges.
 */
class AddressPolicy
{
    private const BLOCKED = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6: unspecified, loopback, IPv4-mapped, NAT64, discard, Teredo, documentation, 6to4,
        // unique-local, link-local, site-local, multicast
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function isPublic(string $ip): bool
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        foreach (self::BLOCKED as $cidr) {
            if ($this->inRange($packed, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $networkPacked = inet_pton($network);

        if (strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);

        if (substr($packed, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }
}
