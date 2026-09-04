<?php

declare(strict_types=1);

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
