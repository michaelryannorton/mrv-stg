import { Badge } from '@/components/ui/badge';

// access_scope values come straight from the source-level classification in the Victor Valley
// corpus (see community/Victor Valley Event Ecosystem - Coverage Gaps and Research Queue.md,
// "Access-scope classification"). 'public' is the expected, unremarkable case and gets no badge;
// everything else means the event may not be open to everyone.
export const ACCESS_SCOPE_META: Record<string, { label: string; className: string }> = {
    mixed: { label: 'Mixed access', className: 'border-amber-600/40 bg-amber-600/10 text-amber-700 dark:text-amber-400' },
    public_with_eligibility: { label: 'Eligibility-gated', className: 'border-amber-600/40 bg-amber-600/10 text-amber-700 dark:text-amber-400' },
    public_with_registration: { label: 'Registration required', className: 'border-sky-600/40 bg-sky-600/10 text-sky-700 dark:text-sky-400' },
    unknown: { label: 'Unclassified', className: 'text-muted-foreground' },
};

export function AccessScopeBadge({ accessScope }: { accessScope: string }) {
    const meta = ACCESS_SCOPE_META[accessScope];
    if (!meta) return null;
    return (
        <Badge variant="outline" className={meta.className}>
            {meta.label}
        </Badge>
    );
}
