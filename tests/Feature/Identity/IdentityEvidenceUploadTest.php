<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Contracts\IdentityMatcher;
use App\Data\IdentityMatchResult;
use App\Models\Branch;
use App\Models\IdentityEvidence;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class IdentityEvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_registration_session_stores_both_images_privately_with_anonymous_keys(): void
    {
        Storage::fake('identity');
        $participant = $this->participant('Ayu Pratiwi');

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/identity-evidence', [
                'identity_document' => UploadedFile::fake()->image('ayu-ktp.jpg', 1200, 800)->size(400),
                'initial_selfie' => UploadedFile::fake()->image('ayu-selfie.png', 800, 1000)->size(350),
            ])
            ->assertRedirect('/registration/received')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('identity_evidence', 2);
        $this->assertSame('private', config('filesystems.disks.identity.visibility'));

        foreach (IdentityEvidence::query()->get() as $evidence) {
            Storage::disk('identity')->assertExists($evidence->object_key);
            $this->assertStringNotContainsStringIgnoringCase('ayu', $evidence->object_key);
            $this->assertStringNotContainsStringIgnoringCase('ktp', $evidence->object_key);
            $this->assertStringNotContainsStringIgnoringCase('selfie', $evidence->object_key);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence->checksum_sha256);
            $this->assertGreaterThan(0, $evidence->size_bytes);
            $this->assertGreaterThanOrEqual(480, $evidence->width);
            $this->assertGreaterThanOrEqual(480, $evidence->height);
        }

        $this->get('/registration/received')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('registration/received')
                ->where('identityEvidence.authorized', true)
                ->where('identityEvidence.complete', true)
                ->where('identityEvidence.outcome', 'pending')
                ->where('identityEvidence.manualStatus', 'pending')
            );
    }

    public function test_upload_requires_the_registration_bound_session_and_rejects_expired_sessions(): void
    {
        Storage::fake('identity');
        $participant = $this->participant('Ayu Pratiwi');
        $files = $this->validFiles();

        $this->post('/registration/identity-evidence', $files)->assertForbidden();

        $this->withSession([
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()->subSecond()->getTimestamp(),
        ])->post('/registration/identity-evidence', $files)->assertForbidden();

        $this->assertDatabaseCount('identity_evidence', 0);
    }

    public function test_spoofed_mime_malformed_and_oversized_images_are_rejected(): void
    {
        Storage::fake('identity');
        $participant = $this->participant('Ayu Pratiwi');

        $cases = [
            'spoofed MIME' => UploadedFile::fake()->createWithContent('identity.jpg', '<?php echo "not an image";'),
            'malformed image' => UploadedFile::fake()->create('identity.png', 20, 'image/png'),
            'oversized image' => UploadedFile::fake()->image('identity.jpg', 1200, 800)->size(5_001),
        ];

        foreach ($cases as $file) {
            $this->withSession($this->authorizedSession($participant))
                ->post('/registration/identity-evidence', [
                    'identity_document' => $file,
                    'initial_selfie' => UploadedFile::fake()->image('selfie.jpg', 800, 800),
                ])
                ->assertSessionHasErrors('identity_document');
        }

        $this->assertDatabaseCount('identity_evidence', 0);
        Storage::disk('identity')->assertDirectoryEmpty('/');
    }

    public function test_images_outside_safe_dimension_bounds_are_rejected(): void
    {
        Storage::fake('identity');
        $participant = $this->participant('Ayu Pratiwi');

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/identity-evidence', [
                'identity_document' => UploadedFile::fake()->image('tiny.jpg', 32, 32),
                'initial_selfie' => UploadedFile::fake()->image('huge.jpg', 8_001, 8_001),
            ])
            ->assertSessionHasErrors(['identity_document', 'initial_selfie']);

        $this->assertDatabaseCount('identity_evidence', 0);
    }

    public function test_matcher_mismatch_is_recorded_only_as_a_review_marker(): void
    {
        Storage::fake('identity');
        $participant = $this->participant('Ayu Pratiwi');
        $matcher = new class implements IdentityMatcher
        {
            public function name(): string
            {
                return 'fake-matcher';
            }

            public function compare(IdentityEvidence $identityDocument, IdentityEvidence $initialSelfie): IdentityMatchResult
            {
                return IdentityMatchResult::mismatch(0.21, 'fake-mismatch');
            }
        };
        $this->app->instance(IdentityMatcher::class, $matcher);

        $this->withSession($this->authorizedSession($participant))
            ->post('/registration/identity-evidence', $this->validFiles())
            ->assertRedirect('/registration/received');

        $this->assertDatabaseHas('identity_verifications', [
            'participant_id' => $participant->id,
            'matcher' => 'fake-matcher',
            'outcome' => 'mismatch',
            'marker' => 'fake-mismatch',
            'manual_status' => 'pending',
        ]);
        $this->assertDatabaseHas('participants', ['id' => $participant->id, 'deleted_at' => null]);
    }

    /** @return array<string, UploadedFile> */
    private function validFiles(): array
    {
        return [
            'identity_document' => UploadedFile::fake()->image('identity.jpg', 1200, 800),
            'initial_selfie' => UploadedFile::fake()->image('selfie.jpg', 800, 1000),
        ];
    }

    /** @return array<string, int> */
    private function authorizedSession(Participant $participant): array
    {
        return [
            'registration.participant_id' => $participant->id,
            'registration.evidence_authorized_until' => now()->addHours(2)->getTimestamp(),
        ];
    }

    private function participant(string $name): Participant
    {
        $branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'Central',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);

        return Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'full_name' => $name,
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
        ]);
    }
}
