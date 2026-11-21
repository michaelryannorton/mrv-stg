<?php

namespace App\Support;

/**
 * Cloudflare's published address ranges, trusted as proxies in bootstrap/app.php.
 *
 * Production's domain is proxied by Cloudflare, so every request reaches the server from one of these
 * addresses and carries the visitor's address in X-Forwarded-For. Trusting only these ranges (not every
 * address) matters because the server is also reachable directly by its IP: a request from anywhere else
 * cannot set its own address by sending the header. Staging is not proxied; no request there comes from
 * these ranges, so the setting changes nothing on staging.
 *
 * Copied from https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6 on 2026-11-20.
 * Cloudflare changes the list rarely; compare it again before each public launch step and once a year.
 */
final class CloudflareProxies
{
    public const RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];
}
