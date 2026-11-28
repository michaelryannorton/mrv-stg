export interface TextSegment {
    text: string;
    /** Set when the segment is a web address; always http or https. */
    href?: string;
}

const ADDRESS = /https?:\/\/[^\s<>"]+/gi;

/** Drops the punctuation that ends a sentence, and a closing bracket that was not opened inside the address. */
function trimAddress(address: string): string {
    let url = address;

    for (;;) {
        const last = url.slice(-1);
        const unopened = (open: string, close: string) => last === close && url.split(open).length < url.split(close).length;

        if (!".,;:!?'’”".includes(last) && !unopened('(', ')') && !unopened('[', ']')) return url;

        url = url.slice(0, -1);
    }
}

/** Splits plain text into words and web addresses, so a page can show the addresses as links. */
export function linkify(text: string): TextSegment[] {
    const segments: TextSegment[] = [];
    let done = 0;

    for (const match of text.matchAll(ADDRESS)) {
        const url = trimAddress(match[0]);

        if (!URL.canParse(url)) continue;

        if (match.index > done) segments.push({ text: text.slice(done, match.index) });
        segments.push({ text: url, href: url });
        done = match.index + url.length;
    }

    if (done < text.length) segments.push({ text: text.slice(done) });

    return segments;
}
