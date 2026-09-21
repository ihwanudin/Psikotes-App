<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Contracts\AssessmentItemContentAuthority;
use App\Services\AssessmentSessions\RegistryAssessmentItemContentAuthority;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * F2 item-delivery Stage 1 (2026-09-21), Lead's explicit review requirement.
 * AlwaysAvailableAssessmentItemContentAuthority (tests/Support/) exists
 * only to let tests unrelated to the item-content gate keep constructing
 * AllocateAndStartAssessmentSession without caring about it -- it must
 * never be reachable from app/, since binding it to the real container
 * would silently undo the fail-closed default
 * (RegistryAssessmentItemContentAuthority, AppServiceProvider) the gate
 * exists to guarantee.
 */
final class AssessmentItemContentAuthorityBindingTest extends TestCase
{
    public function test_no_always_available_item_content_class_exists_under_app(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $this->assertStringNotContainsString(
                'AlwaysAvailable',
                (string) file_get_contents($file->getPathname()),
                "Found an 'AlwaysAvailable' reference in {$file->getPathname()} -- test-only "
                .'item-content fakes must never live under app/, only tests/Support/.',
            );
        }
    }

    public function test_container_binds_the_fail_closed_registry_by_default(): void
    {
        $this->assertInstanceOf(
            RegistryAssessmentItemContentAuthority::class,
            app(AssessmentItemContentAuthority::class),
        );
    }
}
