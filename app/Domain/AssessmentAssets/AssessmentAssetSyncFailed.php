<?php

declare(strict_types=1);

namespace App\Domain\AssessmentAssets;

use RuntimeException;

/**
 * Thrown by SyncIstAssets when a source file cannot be verified onto its
 * target disk (write succeeded but the re-read checksum disagrees). Must
 * propagate all the way out to a non-zero command exit code -- Lead's
 * explicit requirement (2026-09-21): a checksum mismatch fails the deploy,
 * it never falls back to serving old/corrupted asset bytes.
 */
final class AssessmentAssetSyncFailed extends RuntimeException {}
