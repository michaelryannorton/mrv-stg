import { describe, expect, it } from 'vitest';
import { canon } from './html-canon';

/** The comparison helper must say "equal" only for the same DOM, or the golden tests prove nothing. */
describe('canon', () => {
    it('treats the same DOM written differently as equal', () => {
        expect(canon('<a href="x" class="b  a">T &middot; U</a>')).toBe(canon("<a class='b a' href=x>T · U</a>"));
        expect(canon('<div style="color: red; margin:0">x</div>')).toBe(canon('<div style="color:red;margin: 0;">x</div>'));
        expect(canon('<img src="a.jpg" alt="x"/>')).toBe(canon('<img alt="x" src="a.jpg">'));
        expect(canon('<div>\n   <p>a</p>\n   <p>b</p>\n</div>')).toBe(canon('<div><p>a</p><p>b</p></div>'));
        expect(canon('<b>Tom &amp; Jerry</b>')).toBe(canon('<b>Tom & Jerry</b>'));
    });

    it('keeps the differences that change the DOM', () => {
        expect(canon('<p>a</p>')).not.toBe(canon('<p>b</p>'));
        expect(canon('<p>a</p>')).not.toBe(canon('<div>a</div>'));
        expect(canon('<a href="x">a</a>')).not.toBe(canon('<a href="y">a</a>'));
        expect(canon('<a class="a b">a</a>')).not.toBe(canon('<a class="b a">a</a>'));
        expect(canon('<p>a</p><p>b</p>')).not.toBe(canon('<p>b</p><p>a</p>'));
        expect(canon('<p>a</p>')).not.toBe(canon('<p>a</p><p></p>'));
        expect(canon('<span>a</span> <span>b</span>')).not.toBe(canon('<span>a</span><span>b</span>'));
        expect(canon('<p>a b</p>')).not.toBe(canon('<p>ab</p>'));
    });

    it('ignores the space between children of a flex or grid box, and only there', () => {
        expect(canon('<span>a</span> <span>b</span>', 'map-legend')).toBe(canon('<span>a</span><span>b</span>', 'map-legend'));
        expect(canon('<div class="map-legend"><span>a</span> <span>b</span></div>')).toBe(
            canon('<div class="map-legend"><span>a</span><span>b</span></div>'),
        );
        expect(canon('<span>a</span> <span>b</span>', 'other')).not.toBe(canon('<span>a</span><span>b</span>', 'other'));
    });
});
