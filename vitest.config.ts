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
    },
});
