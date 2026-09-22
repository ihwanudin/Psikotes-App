import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));
// Overridable so two concurrent sessions can each run this fixture without
// colliding on the same port. Keep in sync with the `origin` in
// browser.test.mjs — this port is not read from a shared module because
// that file is designed to be portable/inlined into other Playwright CLI
// runners (see its own comment).
const port = Number(process.env.PARTICIPANT_LOBBY_FIXTURE_PORT) || 8011;

export default defineConfig({
    root,
    envDir: false,
    publicDir: false,
    // Adding a new fixture? Register oncamTokenRuntimeBridge() here too —
    // see https://github.com/ihwanudin/Psikotes-App/pull/53 for why (each
    // fixture is its own Vite process, and the generated token CSS is
    // gitignored, so nothing else produces it for that process).
    plugins: [react(), oncamTokenRuntimeBridge(), tailwindcss()],
    server: {
        host: '127.0.0.1',
        port,
        strictPort: true,
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/participant-lobby`,
        emptyOutDir: false,
    },
});
