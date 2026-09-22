import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));
// Overridable so two concurrent sessions can each run this fixture without
// colliding, and different by default from the other fixtures' ports
// (ParticipantLobby 8011, IntegratedCheckout 8012, PapiRunner 8013,
// RmibRunner 8014).
const port = Number(process.env.IST_SUBTEST_SCREEN_FIXTURE_PORT) || 8017;

// Dedicated frontend fixture. No Laravel plugins, artisan, dotenv, or backend process.
export default defineConfig({
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
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/ist-subtest-screen`,
        emptyOutDir: false,
    },
});
