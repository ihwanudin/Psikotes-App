import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));

export default defineConfig({
    root,
    envDir: false,
    publicDir: false,
    plugins: [react(), tailwindcss()],
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
