<?php

namespace App\Support\SafeHttp;

/** Looks up every address a host name points to. A class of its own so tests can supply fixed answers. */
class HostResolver
{
    /** @return list<string> IPv4 addresses first, then IPv6; empty when the name does not resolve. */
    public function resolve(string $host): array
    {
        $v4 = [];
        $v6 = [];

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip'])) {
                $v4[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $v6[] = $record['ipv6'];
            }
        }

        return array_values(array_unique([...$v4, ...$v6]));
    }
}
