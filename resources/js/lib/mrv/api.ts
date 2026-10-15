/**
 * Where the browser reads weather data from, in one place.
 *
 * Today this is the legacy API (https://data.mojaverivervalley.org/v1), exactly as production's pages call it
 * (port decision D1, step a). Public access to that API will be shut down before launch (Michael, 2026-10-14), so
 * before launch the same request shapes must be served by the Laravel app itself and this default changes to its
 * address (or VITE_MRV_API_BASE is set at build time). No other file should contain the address.
 */
export const MRV_API_BASE: string = (import.meta.env.VITE_MRV_API_BASE as string | undefined) ?? 'https://data.mojaverivervalley.org/v1';

/** A minimal view of `fetch`, so tests can supply a fake. */
export type FetchLike = (input: string, init?: { cache?: RequestCache }) => Promise<{ ok: boolean; status: number; json(): Promise<unknown> }>;

/** GET one address and parse the JSON body; a non-2xx answer is an error naming the status and the address (as production's data.js). */
export async function fetchJson<T = unknown>(
    url: string,
    fetchImpl: FetchLike = (u, i) => fetch(u, i),
    /** The page's own wording for a failed request, where production's page has one ("Market fetch failed: HTTP 503"). */
    failureMessage: (status: number) => string = (status) => `API fetch failed: HTTP ${status} (${url})`,
): Promise<T> {
    const res = await fetchImpl(url, { cache: 'no-store' });
    if (!res.ok) throw new Error(failureMessage(res.status));
    return (await res.json()) as T;
}
