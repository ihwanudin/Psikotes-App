import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { oncamTokenRuntimeBridge } from '../../../tools/design-tokens/oncam-runtime-bridge.mjs';

const root = fileURLToPath(new URL('./', import.meta.url));
const project = fileURLToPath(new URL('../../../', import.meta.url));
// Overridable so two concurrent sessions can each run this fixture without
// colliding on the same port. Defaults to 8013, not 8011 (which
// ParticipantLobby and IntegratedCheckout both already default to and can
// collide with each other on) — a stale dev server from one of those two
// fixtures left running in another worktree cost real debugging time once
// already (glm/lobby-320-overflow's PR #58); a distinct default sidesteps
// that specific collision for this fixture, though it doesn't fix the
// underlying ParticipantLobby/IntegratedCheckout shared default.
const port = Number(process.env.PAPI_RUNNER_FIXTURE_PORT) || 8013;

export default defineConfig({
    root,
    envDir: false,
    publicDir: false,
    // Adding a new fixture? Register oncamTokenRuntimeBridge() here too —
    // see https://github.com/ihwanudin/Psikotes-App/pull/53 for why (each
    // fixture is its own Vite process, and the generated token CSS is
    // gitignored, so nothing else produces it for that process).
    plugins: [react(), oncamTokenRuntimeBridge(), tailwindcss()],
    // papi-item.tsx/papi-item-nav.tsx/papi-summary.tsx import from
    // "@/lib/utils" and "@/components/ui/button" — this fixture 500'd on
    // every one of those imports without this alias (ParticipantLobby's
    // vite.config.ts doesn't need it, since lobby.tsx has no "@/" imports;
    // IntegratedCheckout's already has the identical line for the same
    // reason).
    resolve: { alias: { '@': `${project}/resources/js` } },
    server: {
        host: '127.0.0.1',
        port,
        strictPort: true,
        fs: { allow: [project] },
    },
    build: {
        outDir: `${project}/storage/app/private/verification/papi-runner`,
        emptyOutDir: false,
    },
});
