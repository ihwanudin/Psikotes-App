import { spawnSync } from 'node:child_process';
import {
    closeSync,
    existsSync,
    mkdirSync,
    openSync,
    readFileSync,
    writeFileSync,
} from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

// Requires an already running, isolated fixture (default port 8012, see
// vite.config.ts); never starts a backend or installs packages.
const root = fileURLToPath(new URL('../../../', import.meta.url));
const cli = process.argv[2];
// Keep in sync with vite.config.ts's default. Overridable so two concurrent
// sessions can each run this fixture without colliding.
const PORT = Number(process.env.INTEGRATED_CHECKOUT_FIXTURE_PORT) || 8012;
const DEFAULT_ORIGIN = 'http://127.0.0.1:8012';
const origin = `http://127.0.0.1:${PORT}`;

if (!cli || !path.isAbsolute(cli) || !existsSync(cli)) {
    throw new Error(
        'Pass the absolute path of the existing Playwright CLI script.',
    );
}

const output = path.join(root, 'output/playwright/checkout-checkpoint');
mkdirSync(output, { recursive: true });

for (const directory of [
    'checkout-combined',
    'checkout-unselected',
    'payment-refresh',
    'partial-profile',
]) {
    mkdirSync(path.join(root, 'output/playwright', directory), {
        recursive: true,
    });
}

const suites = [
    {
        name: 'baseline',
        file: 'browser-interactions.mjs',
        entry: 'verifyCheckoutInteractions',
        count: 10,
        initialNavigation: true,
    },
    {
        name: 'optional',
        file: 'browser-interactions.mjs',
        entry: 'verifyOptionalEmail',
        count: 9,
    },
    {
        name: 'keyboard-reflow',
        file: 'keyboard-reflow.mjs',
        entry: 'verifyCheckoutKeyboardAndReflow',
        count: 6,
        captures: 6,
    },
    {
        name: 'unselected',
        file: 'unselected-payment.mjs',
        count: 2,
        captures: 6,
    },
    { name: 'payment-refresh', file: 'payment-refresh.mjs', count: 8 },
    { name: 'partial-profile', file: 'partial-profile.mjs', count: 9 },
];
const guard = `
 const checkpointErrors = [];
 const origin = '${origin}';
 await page.setViewportSize({width:1280,height:1000});
 await page.route('**/*', route => {
  const url=route.request().url();
  if (!url.startsWith(origin+'/') || url.startsWith(origin+'/api/')) { checkpointErrors.push('Unexpected request '+url); return route.abort(); }
  if (url===origin+'/favicon.ico') return route.fulfill({status:204,body:''});
  return route.continue();
 });
 page.on('pageerror', error=>checkpointErrors.push(error.message));
 page.on('console', message=>{if(['error','warning'].includes(message.type())) checkpointErrors.push(message.text());});
 page.on('requestfailed', request=>checkpointErrors.push('Failed request '+request.url()));
 page.on('response', response=>{if(response.status()>=400) checkpointErrors.push('HTTP '+response.status());});
`;
const command = (session, args, log) => {
    const fd = openSync(log, 'w');

    try {
        const result = spawnSync(
            process.execPath,
            [cli, `-s=${session}`, ...args],
            {
                cwd: root,
                stdio: ['ignore', fd, fd],
                timeout: 240000,
                windowsHide: true,
            },
        );

        if (result.error || result.status !== 0) {
            throw new Error(`CLI failed; inspect ${log}`);
        }
    } finally {
        closeSync(fd);
    }
};
const baseline = spawnSync('git', ['rev-parse', 'HEAD'], {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
});
const report = {
    date: new Date().toISOString(),
    baseline: baseline.stdout.trim(),
    suites: [],
};

for (const suite of suites) {
    const session = `checkout-cp-${process.pid}-${suite.name}`;
    const log = path.join(output, `${suite.name}.log`);
    const result = { name: suite.name, session, pass: false, log };

    try {
        // Each suite file hardcodes DEFAULT_ORIGIN itself (they're written
        // to stay portable/self-contained), so propagate a non-default port
        // here rather than requiring every suite file to read process.env
        // (the run-code sandbox these are ultimately executed in doesn't
        // expose it anyway — see ParticipantLobby/browser.test.mjs's comment
        // for the same constraint).
        const source = readFileSync(
            new URL(suite.file, import.meta.url),
            'utf8',
        ).replaceAll(DEFAULT_ORIGIN, origin);
        // Only baseline expects an existing document. Other helpers install their
        // own routes before navigating; an extra goto races their unrouteAll.
        const navigation = suite.initialNavigation
            ? 'await page.goto(origin);'
            : '';
        const code = suite.entry
            ? `${source.replaceAll('export async function', 'async function')}\n${guard}\n${navigation}\nconst result=await ${suite.entry}(page);`
            : `${guard}\nconst result=await (${source.trim().replace(/;$/, '')})(page);`;
        const runner = path.join(output, `${suite.name}.js`);
        writeFileSync(
            runner,
            `async(page)=>{\n${code}\nif(checkpointErrors.length) throw new Error(JSON.stringify(checkpointErrors));\nreturn result;\n}`,
        );
        command(
            session,
            ['open', 'about:blank', '--headed'],
            path.join(output, `${suite.name}-open.log`),
        );
        command(session, ['run-code', '--filename', runner], log);
        const outputText = readFileSync(log, 'utf8');
        const json = outputText.match(/^### Result\r?\n([^\r\n]+)/m)?.[1];

        if (!json || outputText.includes('### Error')) {
            throw new Error(`Browser assertion failed; inspect ${log}`);
        }

        const data = JSON.parse(json);
        result.checkpoints = (
            Array.isArray(data) ? data : (data.results ?? data.keyboard)
        ).length;
        result.captures = data.captures?.length ?? 0;

        if (
            result.checkpoints !== suite.count ||
            result.captures !== (suite.captures ?? 0)
        ) {
            throw new Error(
                'Unexpected result count; do not silently skip assertions',
            );
        }

        result.pass = true;
    } catch (error) {
        result.error = error.message;
    } finally {
        try {
            command(
                session,
                ['close'],
                path.join(output, `${suite.name}-close.log`),
            );
        } catch (error) {
            result.pass = false;
            result.error = error.message;
        }
    }

    report.suites.push(result);
    writeFileSync(
        path.join(output, 'summary.json'),
        JSON.stringify(report, null, 2),
    );
    console.log(JSON.stringify(result));
}

process.exitCode = report.suites.every((suite) => suite.pass) ? 0 : 1;
