import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));

// Dedicated frontend fixture. No Laravel plugins, artisan, dotenv, or backend process.
export default defineConfig(({ mode }) => ({
    root,
    envDir: false,
    publicDir: false,
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': `${project}/resources/js` } },
    server: {
        host: '127.0.0.1',
        port: 8011,
        strictPort: true,
        headers: {
            'Cache-Control': 'no-store',
            'Referrer-Policy': 'no-referrer',
        },
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/frontend-${mode}`,
        emptyOutDir: false,
        ...(mode === 'test' ? { ssr: `${root}/checkout.test.tsx` } : {}),
    },
}));
