import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));

export default defineConfig({
    root,
    envDir: false,
    publicDir: false,
    // Generates resources/css/oncam-design-tokens.generated.css, which
    // resources/css/app.css imports by real relative path. Must run here too:
    // this fixture's dev server is a separate process from the main app's,
    // and the generated file is gitignored (never trusted stale).
    plugins: [react(), oncamTokenRuntimeBridge(), tailwindcss()],
    server: {
        host: '127.0.0.1',
        port: 8011,
        strictPort: true,
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/participant-lobby`,
        emptyOutDir: false,
    },
});
