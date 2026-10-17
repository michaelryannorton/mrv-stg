import { afterEach, describe, expect, it, vi } from 'vitest';

describe('where the data comes from', () => {
    afterEach(() => {
        vi.unstubAllEnvs();
        vi.resetModules();
    });

    it("is the app's own endpoint on the same origin unless the build says otherwise", async () => {
        vi.stubEnv('VITE_MRV_API_BASE', undefined as unknown as string);
        vi.resetModules();
        const { MRV_API_BASE } = await import('./api');
        expect(MRV_API_BASE).toBe('/data/v1');
    });

    it('can be pointed at another address at build time (the tests use the legacy one)', async () => {
        vi.resetModules();
        const { MRV_API_BASE } = await import('./api');
        expect(MRV_API_BASE).toBe('https://data.mojaverivervalley.org/v1');
    });
});
