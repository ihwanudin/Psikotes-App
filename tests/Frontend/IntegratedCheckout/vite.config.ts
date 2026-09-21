import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));

// Dedicated frontend fixture. No Laravel plugins, artisan, dotenv, or backend process.
export default defineConfig(({ mode }) => ({
    root,
    envDir: false,
    publicDir: false,
    // Adding a new fixture? Register oncamTokenRuntimeBridge() here too —
    // see https://github.com/ihwanudin/Psikotes-App/pull/53 for why (each
    // fixture is its own Vite process, and the generated token CSS is
    // gitignored, so nothing else produces it for that process).
    plugins: [react(), oncamTokenRuntimeBridge(), tailwindcss()],
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
