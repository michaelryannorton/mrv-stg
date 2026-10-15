/** Escape text for use inside an HTML string (Leaflet popups and tooltips take HTML strings). */
export function escapeHtml(value: unknown): string {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/** An address from data that is safe to put in an href: http or https only (anything else becomes undefined). */
export function safeHttpUrl(value: unknown): string | undefined {
    return typeof value === 'string' && /^https?:\/\//i.test(value.trim()) ? value : undefined;
}
