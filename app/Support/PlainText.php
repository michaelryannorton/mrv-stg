<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns a feed's description (HTML or plain text, from a third party) into plain text for a public
 * page: no markup, paragraphs kept, a link's address kept beside its text. The result is data, not
 * HTML, so a page must show it as text. The stored description is not changed by this.
 */
class PlainText
{
    /** Longer input is cut before parsing; a description this long is not a description. */
    private const MAX_INPUT = 60000;

    /** Elements whose content is not text for a reader. */
    private const SKIP = ['script', 'style', 'head', 'noscript', 'template', 'iframe', 'object', 'embed', 'svg', 'math', 'title'];

    /** Elements that start and end a paragraph. */
    private const BLOCKS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote',
        'section', 'article', 'header', 'footer', 'figure', 'pre', 'hr',
    ];

    public static function clean(?string $value): ?string
    {
        $value = mb_substr(mb_scrub((string) $value), 0, self::MAX_INPUT);

        if (trim($value) === '') {
            return null;
        }

        $text = preg_match('#<(/?[a-z][a-z0-9]*)(\s[^<>]*)?/?>#i', $value)
            ? self::fromHtml($value)
            : html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

        // Addresses wrapped by Google (calendar descriptions) are shown as where they lead.
        $text = preg_replace_callback(
            '#https?://(?:www\.)?google\.com/url\?[^\s<>"]+#i',
            fn (array $m) => self::unwrap($m[0]),
            $text,
        );

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\t \x{00A0}\x{2007}\x{202F}]+/u', ' ', $text);
        // Control characters and the marks that reorder text have no place in a description.
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]/u', '', $text);
        $text = implode("\n", array_map('trim', explode("\n", $text)));
        $text = trim(preg_replace('/\n{3,}/', "\n\n", $text));

        return $text === '' ? null : $text;
    }

    /** One line, for a place name or a title. */
    public static function line(?string $value): ?string
    {
        $text = self::clean($value);

        return $text === null ? null : trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The start of an already cleaned text, on one line, cut at a word. Web addresses are left out:
     * they are noise in a preview and are in the full text.
     */
    public static function excerpt(?string $clean, int $length = 180): ?string
    {
        $text = preg_replace('#\(?\bhttps?://\S+#i', '', (string) $clean);
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length + 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $space ?: $length), ' ,;:.-–—').'…';
    }

    private static function fromHtml(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // A calendar description often mixes real line breaks with a link or two. When nothing in
        // the markup makes the lines, the line breaks are the author's and are kept.
        $keepNewlines = ! preg_match('#<(br|p|div|li|h[1-6])[\s/>]#i', $html);

        return self::walk($document->documentElement, $keepNewlines);
    }

    private static function walk(?DOMNode $node, bool $keepNewlines): string
    {
        if ($node === null) {
            return '';
        }

        if ($node instanceof DOMText) {
            return $keepNewlines ? $node->data : preg_replace('/\s+/u', ' ', $node->data);
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::SKIP, true)) {
            return '';
        }

        if ($tag === 'br') {
            return "\n";
        }

        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= self::walk($child, $keepNewlines);
        }

        return match (true) {
            $tag === 'a' => self::link($node->getAttribute('href'), $inner),
            $tag === 'li' => "\n• ".trim($inner),
            $tag === 'tr' => "\n".$inner,
            $tag === 'td', $tag === 'th' => $inner.' ',
            in_array($tag, self::BLOCKS, true) => "\n\n".$inner."\n\n",
            default => $inner,
        };
    }

    /** The link's words followed by where it goes, or only the address when the words are the address. */
    private static function link(string $href, string $text): string
    {
        $href = trim($href);

        if (preg_match('#^mailto:([^?]+)#i', $href, $m)) {
            $address = rawurldecode($m[1]);

            return str_contains(mb_strtolower($text), mb_strtolower($address)) ? $text : trim($text.' ('.$address.')');
        }

        if (! preg_match('#^https?://#i', $href)) {
            return $text;
        }

        $href = str_replace([' ', "\n", "\t"], ['%20', '', ''], self::unwrap($href));
        $words = trim($text);

        if ($words === '' || self::bare(self::unwrap($words)) === self::bare($href)) {
            return $href;
        }

        return $words.' ('.$href.')';
    }

    /** google.com/url?q=… leads somewhere else; give that address when it is a web address. */
    private static function unwrap(string $url): string
    {
        $parts = parse_url($url);

        if (! in_array(strtolower($parts['host'] ?? ''), ['google.com', 'www.google.com'], true) || ($parts['path'] ?? '') !== '/url') {
            return $url;
        }

        parse_str(html_entity_decode($parts['query'] ?? ''), $query);
        $target = $query['q'] ?? $query['url'] ?? null;

        return is_string($target) && preg_match('#^https?://#i', $target) ? $target : $url;
    }

    /** An address without the parts people leave out when they type it. */
    private static function bare(string $url): string
    {
        return rtrim(preg_replace('#^(https?://)?(www\.)?#i', '', mb_strtolower(trim($url))), '/');
    }
}
