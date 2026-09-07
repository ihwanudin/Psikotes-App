<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '--asset-tests') {
    checkoutAssetTests(true);

    return;
}

if (($argv[1] ?? '') === '--asset-pure-tests') {
    checkoutAssetTests(false);

    return;
}

if (($argv[1] ?? '') === '--inspector-tests') {
    checkoutInspectorTests();

    return;
}

if (($argv[1] ?? '') === '--contract-tests') {
    checkoutContractTests();

    return;
}

// Only new synthetic text fixtures; no autoloader/application/database.
final class CheckoutIntegrityFixture
{
    public string $directory;

    public string $digest;

    public array $owner = ['pid' => 100, 'started' => '12345', 'nonce' => ''];

    public array $live = [100 => '12345', 101 => '12346', 102 => '12347', 103 => '12348'];

    public array $owned = ['php' => ['pid' => 101, 'started' => '12346'], 'tls' => ['pid' => 102, 'started' => '12347'], 'browser' => ['pid' => 103, 'started' => '12348']];

    public function __construct()
    {
        $this->directory = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/oncam-checkout-'.bin2hex(random_bytes(16));
        $this->owner['nonce'] = str_repeat('a', 64);
        mkdir($this->directory);
        $manifest = [];
        foreach (array_unique([...checkoutBrowserIntegrityCriticalFiles(), 'tests/Support/AssessmentAccessFixture.php', 'tests/Support/AssessmentBillingFixture.php', 'vendor/example/optional.php']) as $relative) {
            $this->put('source/'.$relative, 'synthetic:'.$relative);
            $manifest[$relative] = hash_file('sha256', $this->directory.'/source/'.$relative);
        }
        ksort($manifest);
        $this->put('source-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->digest = hash_file('sha256', $this->directory.'/source-manifest.json');
    }

    public function put(string $relative, string $bytes): void
    {
        $path = $this->directory.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $bytes);
        clearstatcache(true);
    }

    public function run(string $operation, bool $passed = true, ?int $pid = 101): array
    {
        clearstatcache(true);

        return checkoutBrowserIntegrityOperation($this->directory, $this->digest, $this->owner, $operation,
            fn () => checkoutBrowserIntegrityFull($this->directory, $this->digest, static function (): void {}),
            fn (int $id) => $this->live[$id] ?? null, $operation === 'start' ? $this->owned : [], $passed, $pid);
    }

    public function start(): void
    {
        $this->run('pre');
        $this->run('start');
    }

    public function stop(): void
    {
        $this->live = [100 => '12345'];
        $this->run('stop');
    }

    public function finish(): array
    {
        $this->stop();
        $this->live[104] = '12349';
        $this->run('verify', pid: 104);
        $this->run('business', pid: 104);
        unset($this->live[104]);

        return $this->run('post');
    }

    public function record(): array
    {
        return json_decode(file_get_contents($this->directory.'/integrity-evidence.json'), true, 32, JSON_THROW_ON_ERROR);
    }
}

$checks = 0;
$cases = 0;
$assert = static function (bool $condition) use (&$checks): void {
    $checks++;
    if (! $condition) {
        throw new RuntimeException('Synthetic assertion failed');
    }
};
$denied = static function (CheckoutIntegrityFixture $fixture, callable $operation) use ($assert): void {
    $caught = false;
    try {
        $operation();
    } catch (Throwable) {
        $caught = true;
    }
    $assert($caught);
    $assert(is_file($fixture->directory.'/integrity-invalid'));
    $caught = false;
    try {
        $fixture->run('pre');
    } catch (Throwable) {
        $caught = true;
    }
    $assert($caught);
};
$case = static function (string $name, callable $test) use (&$cases): void {
    try {
        $test(new CheckoutIntegrityFixture);
        $cases++;
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAILED synthetic case: '.$name.' ('.$error::class.")\n");
        exit(1);
    }
};

$case('lifecycle and mutable runtime', static function ($f) use ($assert): void {
    $assert($f->run('pre')['state'] === 'preverified');
    $assert(! $f->record()['businessVerified']);
    $assert($f->run('start')['state'] === 'serving');
    $f->put('storage/framework/sessions/synthetic', 'allowed runtime bytes');
    $assert($f->run('serve')['state'] === 'serving');
    $assert($f->finish()['state'] === 'postverified');
});
foreach (['changed', 'missing', 'extra', 'env', 'cache', 'digest', 'manifest-path'] as $mutation) {
    $case('pre-'.$mutation, static function ($f) use ($mutation, $denied): void {
        $path = $f->directory.'/source/vendor/example/optional.php';
        if ($mutation === 'changed') {
            $mtime = filemtime($path);
            $f->put('source/vendor/example/optional.php', str_repeat('x', filesize($path)));
            touch($path, $mtime);
        } elseif ($mutation === 'missing') {
            unlink($path);
        } elseif ($mutation === 'extra') {
            $f->put('source/vendor/example/extra.php', 'extra');
        } elseif ($mutation === 'env') {
            $f->put('source/.env.synthetic', 'synthetic');
        } elseif ($mutation === 'cache') {
            $f->put('source/bootstrap/cache/config.php', 'synthetic');
        } elseif ($mutation === 'digest') {
            $f->digest = str_repeat('0', 64);
        } else {
            $manifest = json_decode(file_get_contents($f->directory.'/source-manifest.json'), true);
            $manifest['../escape'] = str_repeat('0', 64);
            $f->put('source-manifest.json', json_encode($manifest));
            $f->digest = hash_file('sha256', $f->directory.'/source-manifest.json');
        }
        $denied($f, fn () => $f->run('pre'));
    });
}
foreach (['absent', 'truncated', 'run', 'digest', 'owner', 'version', 'nonce', 'owner-dead', 'owner-reused', 'server', 'runtime-dead', 'before-start', 'duplicate-pre'] as $mutation) {
    $case('evidence-'.$mutation, static function ($f) use ($mutation, $denied): void {
        $f->start();
        $record = $f->record();
        if ($mutation === 'absent') {
            unlink($f->directory.'/integrity-evidence.json');
        } elseif ($mutation === 'truncated') {
            $f->put('integrity-evidence.json', '{');
        } elseif (in_array($mutation, ['run', 'digest', 'owner', 'version'], true)) {
            $record[$mutation] = 'wrong';
            $f->put('integrity-evidence.json', json_encode($record));
        } elseif ($mutation === 'nonce') {
            $f->owner['nonce'] = str_repeat('b', 64);
        } elseif ($mutation === 'owner-dead') {
            unset($f->live[100]);
        } elseif ($mutation === 'owner-reused') {
            $f->live[100] = '54321';
        } elseif ($mutation === 'runtime-dead') {
            unset($f->live[102]);
        } elseif ($mutation === 'before-start') {
            $record['state'] = 'preverified';
            $f->put('integrity-evidence.json', json_encode($record));
        }
        $denied($f, fn () => $f->run($mutation === 'duplicate-pre' ? 'pre' : 'serve', pid: $mutation === 'server' ? 999 : 101));
    });
}
$case('critical tamper repair cannot rearm', static function ($f) use ($denied): void {
    $f->start();
    $path = 'source/vendor/autoload.php';
    $bytes = file_get_contents($f->directory.'/'.$path);
    $f->put($path, 'changed');
    $denied($f, fn () => $f->run('serve'));
    $f->put($path, $bytes);
    $denied($f, fn () => $f->run('serve'));
});
foreach (['changed', 'extra', 'missing'] as $mutation) {
    $case('post-noncritical-'.$mutation, static function ($f) use ($mutation, $assert, $denied): void {
        $f->start();
        if ($mutation === 'missing') {
            unlink($f->directory.'/source/vendor/example/optional.php');
        } else {
            $f->put('source/vendor/example/'.($mutation === 'extra' ? 'extra' : 'optional').'.php', 'changed');
        }
        $assert($f->run('serve')['state'] === 'serving');
        $denied($f, fn () => $f->finish());
    });
}
$case('edit restore limitation', static function ($f) use ($assert): void {
    $f->start();
    $path = 'source/vendor/example/optional.php';
    $bytes = file_get_contents($f->directory.'/'.$path);
    $f->put($path, 'changed while serving');
    $assert($f->run('serve')['state'] === 'serving');
    $f->put($path, $bytes);
    $assert($f->finish()['state'] === 'postverified');
});
foreach (['live-process', 'failed-assertions', 'no-business', 'violation', 'post-replay'] as $mutation) {
    $case('completion-'.$mutation, static function ($f) use ($mutation, $denied): void {
        $f->start();
        if ($mutation === 'live-process') {
            $denied($f, fn () => $f->run('stop'));
        } elseif ($mutation === 'failed-assertions') {
            $f->live = [100 => '12345'];
            $denied($f, fn () => $f->run('stop', false));
        } elseif ($mutation === 'no-business') {
            $f->stop();
            $denied($f, fn () => $f->run('post'));
        } elseif ($mutation === 'violation') {
            $f->put('violations.txt', 'synthetic');
            $denied($f, fn () => $f->finish());
        } else {
            $f->finish();
            $denied($f, fn () => $f->run('post'));
        }
    });
}
$case('owner inspection failure', static function ($f) use ($denied): void {
    $denied($f, fn () => checkoutBrowserIntegrityOperation($f->directory, $f->digest, $f->owner, 'pre', static fn () => [], static fn () => throw new RuntimeException('synthetic')));
});
$case('post must wait for verifier process exit', static function ($f) use ($denied): void {
    $f->start();
    $f->stop();
    $f->live[104] = '12349';
    $f->run('verify', pid: 104);
    $f->run('business', pid: 104);
    $denied($f, fn () => $f->run('post'));
});
// Native junctions link only two paths inside this newly generated text fixture.
$junction = static function (CheckoutIntegrityFixture $f, string $relative): array {
    $path = $f->directory.'/'.$relative;
    $target = $f->directory.'/detached';
    rename($path, $target);
    $quote = static fn (string $value) => "'".str_replace("'", "''", $value)."'";
    $script = 'New-Item -ItemType Junction -Path '.$quote($path).' -Target '.$quote($target).' -ErrorAction Stop | Out-Null';
    $process = proc_open([getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true, 'create_new_console' => false]);
    if (! is_resource($process)) {
        throw new RuntimeException('Synthetic junction setup failed');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $output !== '' || $errors !== '') {
        throw new RuntimeException('Synthetic junction setup failed');
    }
    clearstatcache(true);

    return [$path, $target];
};
$restore = static function (array $paths): void {
    // rmdir is nonrecursive: it removes this junction itself, never its contents.
    rmdir($paths[0]);
    rename($paths[1], $paths[0]);
    clearstatcache(true);
};
foreach (['source', 'source/vendor/composer', 'source/vendor/example'] as $relative) {
    $case('pre-junction-'.$relative, static function ($f) use ($relative, $junction, $restore, $denied): void {
        $paths = $junction($f, $relative);
        try {
            $denied($f, fn () => $f->run('pre'));
        } finally {
            $restore($paths);
        }
    });
}
$case('runtime critical-parent junction', static function ($f) use ($junction, $restore, $denied): void {
    $f->start();
    $paths = $junction($f, 'source/vendor/composer');
    try {
        $denied($f, fn () => $f->run('serve'));
    } finally {
        $restore($paths);
    }
});
$case('runtime nested junction persists to post', static function ($f) use ($junction, $restore, $assert, $denied): void {
    $f->start();
    $paths = $junction($f, 'source/vendor/example');
    try {
        $assert($f->run('serve')['state'] === 'serving');
        $denied($f, fn () => $f->finish());
    } finally {
        $restore($paths);
    }
});
$case('transient nested junction limitation', static function ($f) use ($junction, $restore, $assert): void {
    $f->start();
    $paths = $junction($f, 'source/vendor/example');
    try {
        $assert($f->run('serve')['state'] === 'serving');
    } finally {
        $restore($paths);
    }
    $assert($f->finish()['state'] === 'postverified');
});
foreach (['manifest-changed', 'manifest-missing', 'critical-missing', 'runtime-env', 'runtime-cache', 'runtime-root'] as $mutation) {
    $case($mutation, static function ($f) use ($mutation, $denied): void {
        $f->start();
        if ($mutation === 'manifest-changed') {
            $f->put('source-manifest.json', '{}');
        } elseif ($mutation === 'manifest-missing') {
            unlink($f->directory.'/source-manifest.json');
        } elseif ($mutation === 'critical-missing') {
            unlink($f->directory.'/source/vendor/autoload.php');
        } elseif ($mutation === 'runtime-env') {
            $f->put('.env.synthetic', 'synthetic');
        } elseif ($mutation === 'runtime-cache') {
            $f->put('source/bootstrap/cache/config.php', 'synthetic');
        } else {
            rename($f->directory.'/source', $f->directory.'/detached');
        }
        $denied($f, fn () => $f->run('serve'));
    });
}
$case('outside direct temp scope', static function ($f) use ($assert): void {
    $caught = false;
    try {
        checkoutBrowserIntegrityPaths($f->directory.'/source');
    } catch (Throwable) {
        $caught = true;
    }
    $assert($caught);
});
$case('advisory record lock conflict', static function ($f) use ($denied): void {
    $f->start();
    $lock = fopen($f->directory.'/integrity-evidence.json', 'r+');
    flock($lock, LOCK_EX);
    try {
        $denied($f, fn () => $f->run('serve'));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});
$case('full verifier failure cannot repair', static function ($f) use ($denied): void {
    $denied($f, fn () => checkoutBrowserIntegrityOperation($f->directory, $f->digest, $f->owner, 'pre',
        static fn () => throw new RuntimeException('synthetic full failure'), fn ($id) => $f->live[$id] ?? null));
});
$case('business attestation requires same verifier', static function ($f) use ($denied): void {
    $f->start();
    $f->stop();
    $f->live[104] = '12349';
    $f->run('verify', pid: 104);
    $denied($f, fn () => $f->run('business', pid: 999));
});
$case('verifier crash before success', static function ($f) use ($denied): void {
    $f->start();
    $f->stop();
    $f->live[104] = '12349';
    $f->run('verify', pid: 104);
    unset($f->live[104]);
    $denied($f, fn () => $f->run('post'));
});
$case('cannot downgrade marked run to legacy mode', static function ($f) use ($denied): void {
    $f->start();
    $denied($f, fn () => checkoutBrowserIntegrityMode($f->directory, false));
});
$case('owner dies during full precheck', static function ($f) use ($denied): void {
    $denied($f, fn () => checkoutBrowserIntegrityOperation($f->directory, $f->digest, $f->owner, 'pre',
        static function () use ($f): array {
            $result = checkoutBrowserIntegrityFull($f->directory, $f->digest, static function (): void {});
            unset($f->live[100]);

            return $result;
        }, fn ($id) => $f->live[$id] ?? null));
});
echo json_encode(['syntheticCases' => $cases, 'assertions' => $checks, 'passed' => true, 'processProbe' => 'simulated', 'browserOrDatabaseStarted' => false, 'fixturesPreserved' => true], JSON_THROW_ON_ERROR)."\n";

function checkoutInspectorTests(): void
{
    $checks = 0;
    $measurements = [];
    $assert = static function (bool $value) use (&$checks): void {
        $checks++;
        if (! $value) {
            fwrite(STDERR, 'Inspector assertion '.$checks." failed\n");
            throw new RuntimeException('Synthetic inspector assertion failed');
        }
    };
    foreach (['success', 'empty', 'stall', 'error', 'stderr', 'malformed', 'oversize-out', 'oversize-err', 'callback-error'] as $case) {
        $body = match ($case) {
            'success' => 'echo "123456789\\r\\n";',
            'empty' => '',
            'stall' => 'usleep(5000000);',
            'error' => 'exit(3);',
            'stderr' => 'fwrite(STDERR, "SYNTHETIC_PRIVATE");',
            'malformed' => 'echo "SYNTHETIC_PRIVATE";',
            'oversize-out' => 'echo str_repeat("x", 4096); usleep(5000000);',
            'oversize-err' => 'fwrite(STDERR, str_repeat("x", 4096)); usleep(5000000);',
            default => 'usleep(5000000);',
        };
        $spawn = null;
        $started = hrtime(true);
        $error = null;
        $result = null;
        try {
            $result = checkoutBrowserInspectCommand([PHP_BINARY, '-n', '-r', $body], 300,
                static function (array $info) use (&$spawn, $case): void {
                    $spawn = $info;
                    if ($case === 'callback-error') {
                        throw new RuntimeException('SYNTHETIC_PRIVATE');
                    }
                });
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }
        $elapsed = (hrtime(true) - $started) / 1e9;
        $measurements[$case] = round($elapsed, 3);
        $assert($elapsed < 2.0);
        if ($case === 'stall') {
            $assert($elapsed >= 0.29);
        }
        $assert($spawn !== null);
        $assert($error === (in_array($case, ['success', 'empty'], true) ? null : 'Owner inspection failed'));
        $assert($result === ($case === 'success' ? '123456789' : null));
        $assert(! file_exists($spawn['stdout']) && ! file_exists($spawn['stderr']));
        $assert(checkoutBrowserIntegrityProcess($spawn['pid']) === null);
    }
    // Actual tick lookup is restricted to this helper-owned synthetic PHP child.
    $control = null;
    $tickStart = hrtime(true);
    $result = checkoutBrowserInspectCommand([PHP_BINARY, '-n', '-r', 'usleep(1500000); echo "7";'], 3000,
        static function (array $info) use (&$control, $assert): void {
            $control = $info;
            $first = checkoutBrowserIntegrityProcess($info['pid']);
            $second = checkoutBrowserIntegrityProcess($info['pid']);
            $assert(is_string($first) && preg_match('/^[0-9]{1,20}$/D', $first) === 1);
            $assert($first === $second); // Inspector did not terminate the inspected child.
        });
    $measurements['own-live-ticks'] = round((hrtime(true) - $tickStart) / 1e9, 3);
    $assert($result === '7');
    $assert(checkoutBrowserIntegrityProcess($control['pid']) === null);
    $assert(! file_exists($control['stdout']) && ! file_exists($control['stderr']));

    // A real timed-out child while the evidence lock is held must reach invalidation/finally.
    $fixture = new CheckoutIntegrityFixture;
    $caught = false;
    try {
        checkoutBrowserIntegrityOperation($fixture->directory, $fixture->digest, $fixture->owner, 'pre',
            static fn () => checkoutBrowserInspectCommand([PHP_BINARY, '-n', '-r', 'usleep(5000000);'], 100),
            fn ($id) => $fixture->live[$id] ?? null);
    } catch (RuntimeException $error) {
        $caught = $error->getMessage() === 'Owner inspection failed';
    }
    $assert($caught && is_file($fixture->directory.'/integrity-invalid'));
    $lock = fopen($fixture->directory.'/integrity-evidence.json', 'r+');
    $assert(flock($lock, LOCK_EX | LOCK_NB));
    flock($lock, LOCK_UN);
    fclose($lock);
    echo json_encode(['inspectorChecks' => $checks, 'seconds' => $measurements, 'passed' => true], JSON_THROW_ON_ERROR)."\n";
}

function checkoutAssetTests(bool $includeOsJunctions): void
{
    $assertions = 0;
    $assert = static function (bool $condition) use (&$assertions): void {
        $assertions++;
        if (! $condition) {
            throw new RuntimeException('Asset synthetic assertion failed');
        }
    };
    $fixture = static function (): CheckoutIntegrityFixture {
        $f = new CheckoutIntegrityFixture;
        $f->put('source/public/css/checkout-summary-v1.css', 'body { color: #123456; }');
        // Distinct synthetic one-pixel fixture, never the root's brand asset.
        $f->put('source/public/brand/oncam-logo-full-color.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF1sAAAAASUVORK5CYII='));
        $f->put('source/public/js/checkout-confirmation-v1.js', 'export const confirmation = "synthetic";');
        $f->put('source/public/js/checkout-payment-v1.js', 'export const payment = "synthetic";');
        $manifest = json_decode(file_get_contents($f->directory.'/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            'public/css/checkout-summary-v1.css',
            'public/brand/oncam-logo-full-color.png',
            'public/js/checkout-confirmation-v1.js',
            'public/js/checkout-payment-v1.js',
        ] as $relative) {
            $manifest[$relative] = hash_file('sha256', $f->directory.'/source/'.$relative);
        }
        ksort($manifest);
        $f->put('source-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $f->digest = hash_file('sha256', $f->directory.'/source-manifest.json');

        return $f;
    };
    $call = static fn ($f, $uri, $method = 'GET') => checkoutBrowserAssetResponse($f->directory, $f->digest,
        ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method, 'HTTP_COOKIE' => 'SYNTHETIC_PRIVATE', 'HTTP_AUTHORIZATION' => 'SYNTHETIC_PRIVATE', 'HTTP_ACCEPT' => 'text/html']);
    $denied = static function (callable $call) use ($assert): void {
        $caught = false;
        try {
            $call();
        } catch (Throwable) {
            $caught = true;
        }
        $assert($caught);
    };
    $assets = [
        '/css/checkout-summary-v1.css' => 'text/css; charset=UTF-8',
        '/brand/oncam-logo-full-color.png' => 'image/png',
        '/js/checkout-confirmation-v1.js' => 'text/javascript; charset=UTF-8',
        '/js/checkout-payment-v1.js' => 'text/javascript; charset=UTF-8',
    ];
    foreach ($assets as $uri => $mime) {
        $f = $fixture();
        $response = $call($f, $uri);
        $assert($response['status'] === 200);
        $assert($response['headers']['Content-Type'] === $mime);
        $assert($response['headers']['Content-Length'] === (string) strlen($response['body']));
        $assert($response['body'] === file_get_contents($f->directory.'/source/public'.$uri));
        $assert($response['headers']['Cache-Control'] === 'no-store, private');
        $assert($response['headers']['X-Content-Type-Options'] === 'nosniff');
        $assert($response['headers']['Referrer-Policy'] === 'no-referrer');
        $assert(! isset($response['headers']['Set-Cookie']));
        $assert(! str_contains(json_encode($response['headers']), 'SYNTHETIC_PRIVATE'));
        $assert(in_array('public'.$uri, checkoutBrowserIntegrityCriticalFiles(), true));
        foreach (['POST', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'get', ''] as $method) {
            $result = $call($f, $uri, $method);
            $assert($result['status'] === 404 && $result['headers']['Content-Type'] === 'text/plain; charset=UTF-8');
            $assert($result['headers']['Cache-Control'] === 'no-store, private');
        }
        foreach ([$uri.'?x=1', $uri.'?', $uri.'#fragment', str_replace('/', '//', $uri), str_replace('/', '/%2e%2e/', $uri), rawurlencode($uri), '/%2563ss/checkout-summary-v1.css', '/%256as/checkout-payment-v1.js', '/css/../brand/oncam-logo-full-color.png', '/js/../css/checkout-summary-v1.css', '/brand/unknown.png', '/css/unknown.css', '/js/unknown.js', strtoupper($uri)] as $bad) {
            $assert($call($f, $bad)['status'] === 404);
        }
        foreach (['hash', 'missing', 'digest', 'manifest-malformed', 'entry-missing', 'entry-shape', 'empty', 'nul', 'utf8', 'html', 'oversize'] as $mutation) {
            $f = $fixture();
            $path = 'source/public'.$uri;
            if ($mutation === 'missing') {
                unlink($f->directory.'/'.$path);
            } elseif ($mutation === 'digest') {
                $f->digest = str_repeat('0', 64);
            } elseif ($mutation === 'manifest-malformed') {
                $f->put('source-manifest.json', '{');
                $f->digest = hash_file('sha256', $f->directory.'/source-manifest.json');
            } elseif (in_array($mutation, ['entry-missing', 'entry-shape'], true)) {
                $map = json_decode(file_get_contents($f->directory.'/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                if ($mutation === 'entry-missing') {
                    unset($map['public'.$uri]);
                } else {
                    $map['public'.$uri] = true;
                }
                $f->put('source-manifest.json', json_encode($map, JSON_THROW_ON_ERROR));
                $f->digest = hash_file('sha256', $f->directory.'/source-manifest.json');
            } else {
                $bytes = match ($mutation) {
                    'empty' => '',
                    'nul' => "safe\0unsafe",
                    'utf8' => "\xC3\x28",
                    'html' => '  <html>not an asset</html>',
                    'oversize' => str_repeat('x', 262145),
                    default => 'changed',
                };
                $f->put($path, $bytes);
                if (in_array($mutation, ['empty', 'nul', 'utf8', 'html', 'oversize'], true)) {
                    $map = json_decode(file_get_contents($f->directory.'/source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                    $map['public'.$uri] = hash_file('sha256', $f->directory.'/'.$path);
                    $f->put('source-manifest.json', json_encode($map, JSON_THROW_ON_ERROR));
                    $f->digest = hash_file('sha256', $f->directory.'/source-manifest.json');
                }
            }
            $denied(fn () => $call($f, $uri));
        }
    }
    $f = $fixture();
    foreach (['/checkout', '/checkout?normal=1', '/__browser/login', '/unrelated'] as $uri) {
        $assert($call($f, $uri) === null);
    }
    foreach (array_keys($assets) as $uri) {
        $f = $fixture();
        $assert(is_array(checkoutBrowserIntegrityLight($f->directory, $f->digest)));
        $f->put('source/public'.$uri, 'tamper');
        $denied(fn () => checkoutBrowserIntegrityLight($f->directory, $f->digest));
    }
    if ($includeOsJunctions) {
        // Junction targets stay entirely inside a newly-created synthetic fixture.
        foreach (['source', 'source/public', 'source/public/css', 'source/public/brand', 'source/public/js'] as $relative) {
            $f = $fixture();
            $path = $f->directory.'/'.$relative;
            $target = $f->directory.'/asset-detached';
            rename($path, $target);
            $quote = static fn ($value) => "'".str_replace("'", "''", $value)."'";
            checkoutBrowserInspectCommand([getenv('SystemRoot').'/System32/WindowsPowerShell/v1.0/powershell.exe',
                '-NoProfile', '-NonInteractive', '-Command',
                'New-Item -ItemType Junction -Path '.$quote($path).' -Target '.$quote($target).' -ErrorAction Stop | Out-Null']);
            try {
                clearstatcache(true);
                $uri = match ($relative) {
                    'source/public/brand' => '/brand/oncam-logo-full-color.png',
                    'source/public/js' => '/js/checkout-confirmation-v1.js',
                    default => '/css/checkout-summary-v1.css',
                };
                $denied(fn () => $call($f, $uri));
                $denied(fn () => checkoutBrowserIntegrityLight($f->directory, $f->digest));
            } finally {
                rmdir($path); // Nonrecursive removal of this junction itself only.
                rename($target, $path);
                clearstatcache(true);
            }
        }
    }
    $assert(checkoutBrowserAssetFailure(500)['headers'] === [
        'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
    ]);
    foreach ([
        'app/Http/Controllers/IntegratedCheckoutConfirmationController.php',
        'app/Http/Middleware/VerifyCheckoutSessionJsonMutation.php',
        'app/Services/Integrations/StrictCheckoutJson.php',
        'app/Http/Requests/ConfirmIntegratedCheckoutRequest.php',
        'app/Data/Integrations/IntegratedCheckoutConfirmationInput.php',
        'app/Actions/Registration/ConfirmIntegratedCheckout.php',
        'app/Actions/Payments/ActivateSettledAssessment.php',
        'app/Services/Integrations/CheckoutConfirmationFormPresenter.php',
        'app/Registration/ConsentDocument.php',
    ] as $confirmationFile) {
        $assert(in_array($confirmationFile, checkoutBrowserIntegrityCriticalFiles(), true));
    }
    $assert(count(checkoutBrowserIntegrityCriticalFiles()) === 82);
    $expectedAssertions = $includeOsJunctions ? 229 : 219;
    if ($assertions !== $expectedAssertions) {
        throw new RuntimeException('Asset synthetic assertion count changed');
    }
    echo json_encode([
        'assetAssertions' => $assertions,
        'osJunctionsIncluded' => $includeOsJunctions,
        'childProcessFree' => ! $includeOsJunctions,
        'passed' => true,
        'httpOrDatabase' => false,
    ], JSON_THROW_ON_ERROR)."\n";
}

/** Static harness contract checks only: no autoloader, application, database, service, child process, or browser. */
function checkoutContractTests(): void
{
    $source = file_get_contents(__DIR__.'/checkout-session.browser.mjs');
    if (! is_string($source)) {
        throw new RuntimeException('Browser harness source unavailable.');
    }
    $fixtureSource = file_get_contents(__DIR__.'/serve-checkout-session.php');
    if (! is_string($fixtureSource)) {
        throw new RuntimeException('Browser fixture source unavailable.');
    }

    $required = [
        "contractVersion: 'checkout-summary-v2'",
        'script#checkout-summary-v2',
        "['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested', 'actionAvailable', 'action'",
        'summary.payment.actionAvailable === (summary.payment.action !== null)',
        "action.path === '/checkout/payment'",
        "action.currency === 'IDR'",
        "types.includes('dass21') && types.some((type) => type !== 'dass21')",
        "['accepted', 'required'].includes(consent.state)",
        "'dass-not-applicable'",
        "'dass-only-package'",
        'summary.payment.actionAvailable === false && summary.payment.action === null',
        'paymentPosts === 0',
        "url.pathname === '/checkout/confirm'",
        'confirmationPosts === 4',
        'JSON.stringify([422, 419, 409])',
        "['profile[birthDate]', 'profile[educationLevel]', 'profile[gender]', 'profile[intendedField]']",
        "summary.consents.dass.state === 'accepted'",
        "targetPage.locator('form[data-checkout-confirmation]')",
        "confirmationForm.locator('[name^=\"consents[\"]')",
        'PRIVATE_OTHER_PROFILE|PRIVATE_GATEWAY|PRIVATE_INVOICE',
        "page.keyboard.press('Tab')",
    ];
    foreach ($required as $needle) {
        if (! str_contains($source, $needle)) {
            throw new RuntimeException('Browser harness contract marker missing.');
        }
    }
    if (str_contains($source, 'checkout-summary-v1')) {
        throw new RuntimeException('Stale summary-v1 browser contract remains.');
    }
    $fixtureRequired = [
        'IntegratedCheckoutConfirmationController::class',
        'VerifyCheckoutSessionJsonMutation::class',
        "Route::post('/checkout/confirm'",
        "'confirmation' => [",
        "'writer_enabled' => true",
        'checkoutBrowserConfirmationParticipantMatches(',
        "'checkout.confirmed'",
    ];
    foreach ($fixtureRequired as $needle) {
        if (! str_contains($fixtureSource, $needle)) {
            throw new RuntimeException('Browser confirmation fixture marker missing.');
        }
    }

    echo json_encode([
        'contractAssertions' => count($required) + count($fixtureRequired) + 1,
        'passed' => true,
        'browserStarted' => false,
        'serviceStarted' => false,
        'childProcessStarted' => false,
    ], JSON_THROW_ON_ERROR)."\n";
}
