/**
 * Test helper: reduces an HTML string to a canonical form so two strings that build the same DOM compare equal. The golden
 * files hold what the legacy scripts wrote with `innerHTML = '…template strings…'`; the React components are rendered to
 * static markup; both go through `canon()` before they are compared.
 *
 * What is ignored (it does not change the DOM): attribute order, quote style, class and style spacing, `&middot;` against
 * `·` and the other entities, self-closing slashes, and whitespace between block-level elements. Whitespace between two
 * inline elements is kept (as one space) because it is rendered. Everything else (tags, text, attribute values, order of
 * children) must match exactly.
 */

const VOID = new Set(['br', 'img', 'input', 'hr', 'meta', 'link']);
/** Containers laid out as flex or grid (checked in resources/css): whitespace between their children is not rendered. */
const FLEX_OR_GRID = new Set(['map-legend', 'map-controls', 'market-tags', 'card-themes', 'filter-row', 'card-grid', 'grid-markets', 'gallery-grid']);
const INLINE = new Set([
    'a',
    'span',
    'strong',
    'em',
    'b',
    'i',
    'br',
    'img',
    'button',
    'label',
    'small',
    'code',
    'abbr',
    'input',
    'select',
    'textarea',
]);

const NAMED: Record<string, string> = {
    amp: '&',
    lt: '<',
    gt: '>',
    quot: '"',
    apos: "'",
    nbsp: ' ',
    middot: '·',
    mdash: '—',
    ndash: '–',
    hellip: '…',
    rarr: '→',
    larr: '←',
    ldquo: '“',
    rdquo: '”',
    lsquo: '‘',
    rsquo: '’',
    copy: '©',
};

export function decodeEntities(s: string): string {
    return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e: string) => {
        if (e[0] === '#') return String.fromCodePoint(e[1].toLowerCase() === 'x' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10));
        return NAMED[e.toLowerCase()] ?? m;
    });
}

type Node = string | { tag: string; attrs: Record<string, string>; kids: Node[] };

function parseAttrs(src: string): Record<string, string> {
    const out: Record<string, string> = {};
    const re = /([^\s=/>"']+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(src))) {
        let v = decodeEntities(m[2] ?? m[3] ?? m[4] ?? '');
        const name = m[1].toLowerCase();
        if (name === 'class') v = v.split(/\s+/).filter(Boolean).join(' ');
        if (name === 'style')
            v = v
                .split(';')
                .map((d) => d.trim())
                .filter(Boolean)
                .map((d) => {
                    const i = d.indexOf(':');
                    return d.slice(0, i).trim().toLowerCase() + ':' + d.slice(i + 1).trim();
                })
                .join(';');
        out[name] = v;
    }
    return out;
}

function parse(html: string): Node[] {
    const root: Node[] = [];
    const stack: Array<{ tag: string; kids: Node[] }> = [{ tag: '#root', kids: root }];
    const re = /<!--[\s\S]*?-->|<\/([a-zA-Z0-9]+)\s*>|<([a-zA-Z0-9]+)((?:\s+[^<>]*?)?)\s*(\/?)>|([^<]+)/g;
    let m: RegExpExecArray | null;
    while ((m = re.exec(html))) {
        const top = stack[stack.length - 1];
        if (m[0].startsWith('<!--')) continue;
        if (m[1]) {
            const tag = m[1].toLowerCase();
            const i = stack.map((s) => s.tag).lastIndexOf(tag);
            if (i > 0) stack.length = i;
        } else if (m[2]) {
            const tag = m[2].toLowerCase();
            const node = { tag, attrs: parseAttrs(m[3] ?? ''), kids: [] as Node[] };
            top.kids.push(node);
            if (!VOID.has(tag) && !m[4]) stack.push(node);
        } else if (m[5] !== undefined) {
            top.kids.push(decodeEntities(m[5]));
        }
    }
    return root;
}

const isBlockish = (n: Node | undefined): boolean => n === undefined || (typeof n !== 'string' && !INLINE.has(n.tag));

/** Merge adjacent text, collapse whitespace, and drop or trim whitespace that does not render. */
function normalize(kids: Node[], parentClass = ''): Node[] {
    const gap = parentClass.split(' ').some((c) => FLEX_OR_GRID.has(c));
    const merged: Node[] = [];
    for (const k of kids) {
        if (typeof k === 'string' && typeof merged[merged.length - 1] === 'string') merged[merged.length - 1] += k;
        else merged.push(k);
    }
    const out: Node[] = [];
    merged.forEach((k, i) => {
        if (typeof k !== 'string') {
            out.push({ tag: k.tag, attrs: k.attrs, kids: normalize(k.kids, k.attrs.class ?? '') });
            return;
        }
        let t = k.replace(/[ \t\r\n\f]+/g, ' ');
        const prev = merged[i - 1];
        const next = merged[i + 1];
        if (i === 0 || (isBlockish(prev as Node | undefined) && prev !== undefined)) t = t.replace(/^ /, '');
        if (i === merged.length - 1 || (next !== undefined && isBlockish(next))) t = t.replace(/ $/, '');
        if (gap && t.trim() === '') t = '';
        if (t !== '') out.push(t);
    });
    return out;
}

function serialize(nodes: Node[]): string {
    return nodes
        .map((n) => {
            if (typeof n === 'string') return n.replace(/&/g, '&amp;').replace(/</g, '&lt;');
            const attrs = Object.keys(n.attrs)
                .sort()
                .map((k) => ` ${k}="${n.attrs[k].replace(/"/g, '&quot;')}"`)
                .join('');
            return VOID.has(n.tag) ? `<${n.tag}${attrs}>` : `<${n.tag}${attrs}>${serialize(n.kids)}</${n.tag}>`;
        })
        .join('\n');
}

/** `container` is the class of the element the fragment is placed in (the page's flex or grid box), when that matters. */
export const canon = (html: string, container = ''): string => serialize(normalize(parse(html), container));
