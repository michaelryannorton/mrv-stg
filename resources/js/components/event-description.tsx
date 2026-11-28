import { linkify } from '@/lib/linkify';

/** A cleaned description as paragraphs, with its web addresses as links. The text is never read as HTML. */
export default function EventDescription({ text }: { text: string }) {
    return text.split(/\n{2,}/).map((paragraph, i) => (
        <p key={i}>
            {linkify(paragraph).map((segment, j) =>
                segment.href ? (
                    <a key={j} href={segment.href} target="_blank" rel="noopener noreferrer nofollow">
                        {segment.text}
                    </a>
                ) : (
                    segment.text
                ),
            )}
        </p>
    ));
}
