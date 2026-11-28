import { describe, expect, it } from 'vitest';
import { linkify } from './linkify';

const links = (text: string) => linkify(text).flatMap((s) => (s.href ? [s.href] : []));

describe('linkify', () => {
    it('keeps the words and the address in order, losing nothing', () => {
        const text = 'RSVP at https://luma.com/abc123. See you there';

        expect(linkify(text)).toEqual([
            { text: 'RSVP at ' },
            { text: 'https://luma.com/abc123', href: 'https://luma.com/abc123' },
            { text: '. See you there' },
        ]);
        expect(
            linkify(text)
                .map((s) => s.text)
                .join(''),
        ).toBe(text);
    });

    it('leaves out the bracket around an address but keeps one that belongs to it', () => {
        expect(links('the form (https://example.org/form)')).toEqual(['https://example.org/form']);
        expect(links('https://example.org/wiki/Mojave_(desert), then')).toEqual(['https://example.org/wiki/Mojave_(desert)']);
    });

    it('links only web addresses', () => {
        expect(links('javascript:alert(1) data:text/html,x ftp://example.org file:///etc/passwd www.example.org')).toEqual([]);
        expect(links('https:// and http://')).toEqual([]);
    });

    it('returns plain text unchanged', () => {
        expect(linkify('No address here.')).toEqual([{ text: 'No address here.' }]);
        expect(linkify('')).toEqual([]);
    });
});
