<?php

namespace App\Http\Controllers\Data\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzip for the data endpoints. The host's web server (LiteSpeed) does not compress JSON by itself: measured in a browser on staging
 * on 2026-10-16, the 34 MB weather answer crossed the network as 34 MB, where production's reaches the browser as 2.7 MB. The app
 * compresses when the browser says it accepts gzip, and streams the compressed bytes as it goes.
 */
trait CompressesJson
{
    private function acceptsGzip(Request $request): bool
    {
        return str_contains((string) $request->header('Accept-Encoding', ''), 'gzip') && function_exists('deflate_init');
    }

    /** @param  iterable<mixed>  $rows */
    private function streamRows(Request $request, iterable $rows): StreamedResponse
    {
        $gzip = $this->acceptsGzip($request);
        $headers = ['Content-Type' => 'application/json', 'X-Accel-Buffering' => 'no', 'Vary' => 'Accept-Encoding'];
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return response()->stream(function () use ($rows, $gzip) {
            $context = $gzip ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 5]) : null;
            $emit = function (string $text, int $mode = ZLIB_NO_FLUSH) use ($context): void {
                echo $context ? deflate_add($context, $text, $mode) : $text;
            };
            $buffer = '[';
            $first = true;
            $count = 0;
            foreach ($rows as $row) {
                $buffer .= ($first ? '' : ',').json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE);
                $first = false;
                if (++$count % 2000 === 0) {
                    $emit($buffer);
                    $buffer = '';
                    flush();
                }
            }
            $emit($buffer.']', ZLIB_FINISH);
        }, 200, $headers);
    }

    /** @param  array<string, mixed>  $data */
    private function jsonAnswer(Request $request, array $data, array $headers = []): Response
    {
        $body = json_encode($data);
        $headers = array_merge($headers, ['Content-Type' => 'application/json', 'Vary' => 'Accept-Encoding']);
        if ($this->acceptsGzip($request) && strlen($body) > 1024) {
            $body = gzencode($body, 5);
            $headers['Content-Encoding'] = 'gzip';
        }

        return response($body, 200, $headers);
    }
}
