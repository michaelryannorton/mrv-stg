/**
 * Search syntax for the moderation queue: quoted phrases ("open coffee"), -exclude, and OR groups
 * (coffee OR mixer) at the top level — no parentheses/nesting. Terms with no operator are ANDed
 * together, matching how most search boxes read bare words. Anything that isn't a substring match
 * falls back to a fuzzy (typo-tolerant) comparison against each word in the haystack, so this
 * covers exact, boolean, and fuzzy matching in one pass without needing a search backend.
 */

interface Term {
    text: string;
    negate: boolean;
}

function tokenize(query: string): Array<{ type: 'term'; term: Term } | { type: 'or' }> {
    const matches = query.match(/-?"[^"]*"|-?\S+/g) ?? [];
    const tokens: Array<{ type: 'term'; term: Term } | { type: 'or' }> = [];

    for (const raw of matches) {
        if (/^or$/i.test(raw)) {
            tokens.push({ type: 'or' });
            continue;
        }
        if (/^and$/i.test(raw)) {
            continue;
        }

        const negate = raw.startsWith('-');
        const stripped = (negate ? raw.slice(1) : raw).replace(/^"|"$/g, '');
        if (!stripped) continue;

        tokens.push({ type: 'term', term: { text: stripped.toLowerCase(), negate } });
    }

    return tokens;
}

function splitIntoOrGroups(tokens: ReturnType<typeof tokenize>): Term[][] {
    const groups: Term[][] = [[]];
    for (const token of tokens) {
        if (token.type === 'or') {
            groups.push([]);
        } else {
            groups[groups.length - 1].push(token.term);
        }
    }
    return groups.filter((g) => g.length > 0);
}

function levenshtein(a: string, b: string): number {
    if (a === b) return 0;
    if (a.length === 0) return b.length;
    if (b.length === 0) return a.length;

    let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
    for (let i = 1; i <= a.length; i++) {
        const curr = [i];
        for (let j = 1; j <= b.length; j++) {
            curr[j] =
                a[i - 1] === b[j - 1]
                    ? prev[j - 1]
                    : 1 + Math.min(prev[j - 1], prev[j], curr[j - 1]);
        }
        prev = curr;
    }
    return prev[b.length];
}

function fuzzyThreshold(length: number): number {
    if (length <= 3) return 0;
    if (length <= 5) return 1;
    return 2;
}

function termMatches(haystack: string, haystackWords: string[], term: string): boolean {
    if (haystack.includes(term)) return true;

    // Multi-word terms (an unquoted phrase, or a quoted one that missed the exact check above)
    // require every word to fuzzy-match somewhere, not the phrase as a whole.
    const words = term.split(/\s+/).filter(Boolean);
    return words.every((word) => {
        const threshold = fuzzyThreshold(word.length);
        return haystackWords.some((hw) => Math.abs(hw.length - word.length) <= threshold && levenshtein(hw, word) <= threshold);
    });
}

export function matchesQuery(haystack: string, query: string): boolean {
    const q = query.trim();
    if (!q) return true;

    const normalized = haystack.toLowerCase();
    const words = normalized.split(/[^a-z0-9]+/).filter(Boolean);
    const orGroups = splitIntoOrGroups(tokenize(q));

    if (orGroups.length === 0) return true;

    return orGroups.some((group) =>
        group.every((term) => {
            const isMatch = termMatches(normalized, words, term.text);
            return term.negate ? !isMatch : isMatch;
        }),
    );
}
