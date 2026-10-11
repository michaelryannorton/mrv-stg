<?php

namespace App\Support\SafeHttp;

/**
 * A write filter for the response sink: once more than `max` bytes have been written, the write
 * fails and the transfer is aborted, so a visitor-supplied link cannot make the server download an
 * unbounded body. Registered as `safehttp.limit` by SafeFetcher.
 */
class SizeLimitFilter extends \php_user_filter
{
    private int $written = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $max = (int) ($this->params['max'] ?? 0);

        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->written += $bucket->datalen;

            if ($this->written > $max) {
                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
