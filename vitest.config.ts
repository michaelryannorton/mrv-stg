import path from 'node:path';
import { defineConfig } from 'vitest/config';

// JavaScript unit tests for the port's pure code and its React components (rendered to static markup, no browser).
// The PHP suite (`php artisan test`) is separate.
export default defineConfig({
    resolve: { alias: { '@': path.resolve(__dirname, 'resources/js') } },
    esbuild: { jsx: 'automatic' },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        // The goldens and saved responses were recorded from the legacy API, so the tests read the data from its address;
        // api.test.ts checks the real default.
        env: { VITE_MRV_API_BASE: 'https://data.mojaverivervalley.org/v1' },
    },
});
