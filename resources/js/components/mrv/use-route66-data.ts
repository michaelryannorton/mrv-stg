import { loadRoute66Data, type Route66Data } from '@/lib/mrv/route66';
import { useEffect, useState } from 'react';

export type Route66State = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; data: Route66Data };

/** The Route 66 data files (kept after the first load, see loadRoute66Data), with the loading and error states the pages show. */
export function useRoute66Data(): Route66State {
    const [state, setState] = useState<Route66State>({ status: 'loading' });

    useEffect(() => {
        let live = true;
        loadRoute66Data().then(
            (data) => live && setState({ status: 'ready', data }),
            (err: Error) => live && setState({ status: 'error', message: err.message }),
        );

        return () => {
            live = false;
        };
    }, []);

    return state;
}
