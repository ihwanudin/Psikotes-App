<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the framework's own local-disk signed-URL serving
 * (Illuminate\Filesystem\ServeFile, disabled for the ist-assets disk via
 * config/filesystems.php's 'serve' => false) for one reason: that class
 * calls abort_unless() for an invalid signature or a missing file BEFORE
 * it ever sets its Cache-Control header, so those 403/404 responses carry
 * no caching directive at all -- letting a browser heuristically cache a
 * transient negative response (RFC 9111) for a URL that GetAssessmentSessionAssetUrl
 * may re-issue byte-identical within the same second (its signed
 * `expires` timestamp is second-precision). Every branch here sets
 * Cache-Control explicitly, success included, so no gap exists on any
 * path. See AppServiceProvider::configureIstAssetTemporaryUrls() for the
 * matching signed-nonce fix on the issuing side.
 */
final class ServeIstAssetController extends Controller
{
    public function __invoke(Request $request, string $path): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return $this->noStoreEmpty(app()->isProduction() ? 404 : 403);
        }

        try {
            if (! Storage::disk('ist-assets')->exists($path)) {
                return $this->noStoreEmpty(404);
            }

            return Storage::disk('ist-assets')->response($path, headers: $this->headers());
        } catch (PathTraversalDetected) {
            return $this->noStoreEmpty(404);
        }
    }

    private function noStoreEmpty(int $status): Response
    {
        return response('', $status)->withHeaders($this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ];
    }
}
