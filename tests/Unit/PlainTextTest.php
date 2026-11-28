<?php

namespace Tests\Unit;

use App\Support\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Descriptions come from third-party feeds and are shown to the public as plain text. */
class PlainTextTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'paragraphs and breaks' => ['<p>One</p><p>Two<br>Three</p>', "One\n\nTwo\nThree"],
            'a calendar feed\'s shape' => [
                'A group.<br><br>To RSVP:&nbsp;<a href="https://luma.com/abc">https://luma.com/abc</a><br><br>Location:&nbsp;<br><u>Cafe</u>',
                "A group.\n\nTo RSVP: https://luma.com/abc\n\nLocation:\nCafe",
            ],
            'link words keep the address' => ['<a href="https://example.org/form">Sign up</a>', 'Sign up (https://example.org/form)'],
            'google wrapper replaced' => [
                '<a href="https://www.google.com/url?q=https://1MCVictorValley.com&amp;sa=D&amp;usg=xyz">https://1MCVictorValley.com</a>',
                'https://1MCVictorValley.com',
            ],
            'google wrapper in plain text' => ['See https://www.google.com/url?q=https://example.org/a&sa=D today', 'See https://example.org/a today'],
            'google wrapper to a non-web target is left alone' => [
                'https://www.google.com/url?q=javascript:alert(1)', 'https://www.google.com/url?q=javascript:alert(1)',
            ],
            'words that show a different address keep both' => [
                '<a href="https://evil.example/x">https://good.example</a>', 'https://good.example (https://evil.example/x)',
            ],
            'script and style contents removed' => ['<style>p{}</style><p>Hi</p><script>alert(1)</script>', 'Hi'],
            'unsafe link addresses dropped' => ['<a href="javascript:alert(1)">click</a> <a href="data:text/html,x">here</a>', 'click here'],
            'event handlers and images leave nothing' => ['<img src=x onerror=alert(1)><div onclick="x()">Text</div>', 'Text'],
            'mail link keeps the address' => ['<a href="mailto:a%40example.org?subject=x">Write us</a>', 'Write us (a@example.org)'],
            'list items' => ['<ul><li>One</li><li>Two</li></ul>', "• One\n• Two"],
            'plain text keeps its lines' => ["Line one\r\nLine two\n\n\n\nLine three", "Line one\nLine two\n\nLine three"],
            'plain text with a less-than sign' => ['Ages <5 free & welcome', 'Ages <5 free & welcome'],
            'entities decoded' => ['Fish &amp; chips', 'Fish & chips'],
            'line breaks kept when the markup makes none' => ["Bring:\n- water\n<b>Free</b>", "Bring:\n- water\nFree"],
            'accents and emoji survive' => ['<p>Café ☕️ niño</p>', 'Café ☕️ niño'],
            'reordering marks removed' => ["abc\u{202E}def\u{200B}", 'abcdef'],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_cleans(string $input, string $expected): void
    {
        $this->assertSame($expected, PlainText::clean($input));
    }

    public function test_nothing_readable_gives_null(): void
    {
        foreach ([null, '', "  \n ", '<p>&nbsp;</p>', '<script>x</script>'] as $empty) {
            $this->assertNull(PlainText::clean($empty));
        }
        $this->assertNull(PlainText::excerpt(null));
        $this->assertNull(PlainText::excerpt('https://example.org/only'));
    }

    public function test_a_line_has_no_breaks(): void
    {
        $this->assertSame('Cafe 1 Main St', PlainText::line("<p>Cafe</p>\n<p>1 Main St</p>"));
    }

    public function test_the_excerpt_is_short_cut_at_a_word_and_without_addresses(): void
    {
        $this->assertSame('For details: and more', PlainText::excerpt("For details: https://example.org/x\n\nand more"));
        $this->assertSame('Sign up', PlainText::excerpt('Sign up (https://example.org/form)'));
        $this->assertSame('alpha beta…', PlainText::excerpt('alpha beta gamma delta', 13));
        $this->assertLessThanOrEqual(181, mb_strlen(PlainText::excerpt(str_repeat('word ', 200))));
    }

    public function test_very_long_input_is_bounded(): void
    {
        $this->assertLessThanOrEqual(60000, mb_strlen(PlainText::clean(str_repeat('a', 200000))));
    }
}
