import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));
// Overridable so two concurrent sessions can each run this fixture
// without colliding — default (8016) is distinct from every other
// fixture's default (8011 ParticipantLobby, 8012 IntegratedCheckout,
// 8015 ProctoringConsent).
const port = Number(process.env.CAMERA_CAPTURE_FIXTURE_PORT) || 8016;

export default defineConfig({
    root,
    envDir: false,
    publicDir: false,
    plugins: [react(), oncamTokenRuntimeBridge(), tailwindcss()],
    resolve: { alias: { '@': `${project}/resources/js` } },
    server: {
        host: '127.0.0.1',
        port,
        strictPort: true,
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/camera-capture`,
        emptyOutDir: false,
    },
});
