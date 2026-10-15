/** The city selector the weather pages share (production: a bare <select> of the named cities). */
export function CitySelect({ id, names, value, onChange }: { id: string; names: string[]; value: string; onChange: (name: string) => void }) {
    return (
        <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
            {names.map((n) => (
                <option key={n} value={n}>
                    {n}
                </option>
            ))}
        </select>
    );
}

/** The page's address with `city` set, kept in step with the selector (production: history.replaceState). Inertia's own history state is kept. */
export function rememberCity(name: string): void {
    const params = new URLSearchParams(window.location.search);
    params.set('city', name);
    window.history.replaceState(window.history.state, '', `${window.location.pathname}?${params.toString()}`);
}

export const cityFromUrl = (): string | null => new URLSearchParams(window.location.search).get('city');
