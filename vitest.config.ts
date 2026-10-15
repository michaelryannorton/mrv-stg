import { defineConfig } from 'vitest/config';

// JavaScript unit tests for the port's pure code (resources/js/lib). The PHP suite (`php artisan test`) is separate.
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
});
