import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));
// Overridable so two concurrent sessions can each run this fixture without
// colliding, and different by default from ParticipantLobby's port so the
// two fixtures don't collide with each other either. run-checkpoint.mjs
// reads the same env var and propagates the resolved port into each
// suite file it generates a runner from.
const port = Number(process.env.INTEGRATED_CHECKOUT_FIXTURE_PORT) || 8012;

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
        port,
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
