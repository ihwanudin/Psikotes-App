<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Illuminate\Testing\TestResponse;
use ReflectionProperty;
use Tests\TestCase;

final class TrustedProxyPreloadSchemeTest extends TestCase
{
    private string $buildDirectory;

    private string $assetMarker;

    /** @var list<string> */
    private array $assetFiles = [];

    private Vite $originalViteInstance;

    /** @var array<string, array<string, mixed>> */
    private array $originalManifestCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildDirectory = '__test-vite-proxy-'.bin2hex(random_bytes(8));
        $this->assetMarker = hash('sha256', 'trusted-proxy-preload-scheme-fixture');
        $this->originalViteInstance = app(Vite::class);

        $manifests = new ReflectionProperty(Vite::class, 'manifests');
        $this->originalManifestCache = $manifests->getValue();

        $testVite = clone $this->originalViteInstance;
        $testVite->flush();
        $testVite
            ->useBuildDirectory($this->buildDirectory)
            ->useHotFile(public_path($this->buildDirectory.'/hot'));
        app()->instance(Vite::class, $testVite);
        ViteFacade::clearResolvedInstance(Vite::class);

        $assetDirectory = public_path($this->buildDirectory.'/assets');
        $this->assertTrue(mkdir($assetDirectory, 0777, true));

        $assets = [
            'proxy-style-'.$this->assetMarker.'.css' => '/* '.$this->assetMarker.' */',
            'proxy-app-'.$this->assetMarker.'.js' => '// '.$this->assetMarker,
            'proxy-welcome-'.$this->assetMarker.'.js' => '// '.$this->assetMarker,
        ];
        foreach ($assets as $name => $content) {
            $path = $assetDirectory.'/'.$name;
            $this->assetFiles[] = $path;
            $this->assertNotFalse(file_put_contents($path, $content));
            $this->assertSame(hash('sha256', $content), hash_file('sha256', $path));
        }

        $manifest = [
            'resources/css/app.css' => [
                'file' => 'assets/proxy-style-'.$this->assetMarker.'.css',
                'isEntry' => true,
                'src' => 'resources/css/app.css',
            ],
            'resources/js/app.tsx' => [
                'file' => 'assets/proxy-app-'.$this->assetMarker.'.js',
                'isEntry' => true,
                'src' => 'resources/js/app.tsx',
            ],
            'resources/js/pages/welcome.tsx' => [
                'file' => 'assets/proxy-welcome-'.$this->assetMarker.'.js',
                'isEntry' => true,
                'src' => 'resources/js/pages/welcome.tsx',
            ],
        ];
        $this->assertNotFalse(file_put_contents(
            public_path($this->buildDirectory.'/manifest.json'),
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        ));
    }

    protected function tearDown(): void
    {
        app()->instance(Vite::class, $this->originalViteInstance);
        ViteFacade::clearResolvedInstance(Vite::class);
        $manifests = new ReflectionProperty(Vite::class, 'manifests');
        $manifests->setValue(null, $this->originalManifestCache);

        $assetDirectory = public_path($this->buildDirectory.'/assets');
        foreach ($this->assetFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $manifest = public_path($this->buildDirectory.'/manifest.json');
        if (is_file($manifest)) {
            unlink($manifest);
        }
        if (is_dir($assetDirectory)) {
            rmdir($assetDirectory);
        }
        $buildDirectory = public_path($this->buildDirectory);
        if (is_dir($buildDirectory)) {
            rmdir($buildDirectory);
        }

        parent::tearDown();
    }

    public function test_trusted_private_proxy_https_generates_only_https_asset_and_preload_urls(): void
    {
        $response = $this->requestFrom(
            remoteAddress: '172.18.0.1',
            requestScheme: 'http',
            forwardedScheme: 'https',
        );

        $response->assertOk();
        $this->assertStringContainsString(
            $this->assetPrefix('https'),
            (string) $response->getContent(),
        );
        $this->assertStringNotContainsString(
            $this->assetPrefix('http'),
            (string) $response->getContent(),
        );
        $this->assertHttpsPreloads($response->headers->all('Link'));
    }

    public function test_untrusted_peer_cannot_spoof_https_or_forwarded_host(): void
    {
        $response = $this->requestFrom(
            remoteAddress: '203.0.113.10',
            requestScheme: 'http',
            forwardedScheme: 'https',
        );

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString($this->assetPrefix('http'), $content);
        $this->assertStringNotContainsString($this->assetPrefix('https'), $content);
        $this->assertStringNotContainsString('attacker.example', $content);
        $this->assertHttpPreloads($response->headers->all('Link'));
    }

    public function test_plain_http_without_forwarding_remains_http(): void
    {
        $response = $this->requestFrom(
            remoteAddress: '127.0.0.1',
            requestScheme: 'http',
            forwardedScheme: null,
        );

        $response->assertOk();
        $content = (string) $response->getContent();
        $this->assertStringContainsString($this->assetPrefix('http'), $content);
        $this->assertStringNotContainsString($this->assetPrefix('https'), $content);
        $this->assertHttpPreloads($response->headers->all('Link'));
    }

    private function requestFrom(
        string $remoteAddress,
        string $requestScheme,
        ?string $forwardedScheme,
    ): TestResponse {
        $server = [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_HOST' => 'psikotes.oncam.id',
            'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => $requestScheme === 'https' ? '443' : '80',
            'HTTPS' => $requestScheme === 'https' ? 'on' : 'off',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ];

        if ($forwardedScheme !== null) {
            $server['HTTP_X_FORWARDED_PROTO'] = $forwardedScheme;
        }

        return $this->call('GET', $requestScheme.'://psikotes.oncam.id/', server: $server);
    }

    /** @param array<int, string> $headers */
    private function assertHttpsPreloads(array $headers): void
    {
        $this->assertNotEmpty($headers);

        foreach ($headers as $header) {
            $this->assertStringContainsString(
                '<'.$this->assetPrefix('https'),
                $header,
            );
            $this->assertStringNotContainsString('<http://', $header);
            $this->assertStringNotContainsString('attacker.example', $header);
            $this->assertStringContainsString($this->assetMarker, $header);
        }
    }

    /** @param array<int, string> $headers */
    private function assertHttpPreloads(array $headers): void
    {
        $this->assertNotEmpty($headers);

        foreach ($headers as $header) {
            $this->assertStringContainsString(
                '<'.$this->assetPrefix('http'),
                $header,
            );
            $this->assertStringNotContainsString('<https://', $header);
            $this->assertStringNotContainsString('attacker.example', $header);
            $this->assertStringContainsString($this->assetMarker, $header);
        }
    }

    private function assetPrefix(string $scheme): string
    {
        return $scheme.'://psikotes.oncam.id/'.$this->buildDirectory.'/assets/proxy-';
    }
}
