<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Contracts\IdentityMatcher;
use App\Data\IdentityMatchResult;
use App\Models\IdentityEvidence;
use App\Models\IdentityVerification;
use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class StoreIdentityEvidence
{
    public function __construct(
        private RlsContextRunner $runner,
        private IdentityMatcher $matcher,
    ) {}

    public function handle(
        int $participantId,
        UploadedFile $identityDocument,
        UploadedFile $initialSelfie,
    ): IdentityVerification {
        $disk = (string) config('identity.disk', 'identity');
        $stored = [];

        try {
            $stored['identity_document'] = $this->storeFile($disk, $identityDocument);
            $stored['initial_selfie'] = $this->storeFile($disk, $initialSelfie);

            [$verification, $replacedKeys] = $this->runner->run(
                new RlsContext('service'),
                function () use ($participantId, $stored): array {
                    Participant::query()->findOrFail($participantId);

                    $evidence = [];
                    $replacedKeys = [];

                    foreach ($stored as $type => $metadata) {
                        $record = IdentityEvidence::query()->firstOrNew([
                            'participant_id' => $participantId,
                            'type' => $type,
                        ]);

                        if ($record->exists && $record->object_key !== $metadata['object_key']) {
                            $replacedKeys[] = [$record->disk, $record->object_key];
                        }

                        if (! $record->exists) {
                            $record->public_id = (string) Str::ulid();
                        }

                        $record->forceFill($metadata)->save();
                        $evidence[$type] = $record;
                    }

                    $result = $this->match($evidence['identity_document'], $evidence['initial_selfie']);
                    $verification = IdentityVerification::query()->updateOrCreate(
                        ['participant_id' => $participantId],
                        [
                            'matcher' => $this->matcher->name(),
                            'outcome' => $result->outcome,
                            'confidence' => $result->confidence,
                            'marker' => $result->marker,
                            'manual_status' => 'pending',
                            'reviewed_by_admin_id' => null,
                            'checked_at' => now(),
                            'reviewed_at' => null,
                        ],
                    );

                    return [$verification, $replacedKeys];
                },
            );
        } catch (Throwable $exception) {
            foreach ($stored as $metadata) {
                Storage::disk($disk)->delete($metadata['object_key']);
            }

            throw $exception;
        }

        foreach ($replacedKeys as [$oldDisk, $oldKey]) {
            Storage::disk($oldDisk)->delete($oldKey);
        }

        return $verification;
    }

    /** @return array{disk: string, object_key: string, mime_type: string, size_bytes: int, width: int, height: int, checksum_sha256: string} */
    private function storeFile(string $disk, UploadedFile $file): array
    {
        $dimensions = getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw new RuntimeException('Validated identity image dimensions could not be read.');
        }

        $random = Str::lower(Str::random(64));
        $directory = 'evidence/'.substr($random, 0, 2);
        $extension = $file->extension();
        $filesystem = Storage::disk($disk);
        $key = $filesystem->putFileAs(
            $directory,
            $file,
            substr($random, 2).'.'.$extension,
            ['visibility' => 'private'],
        );

        if (! is_string($key)) {
            throw new RuntimeException('Identity evidence could not be stored.');
        }

        try {
            $filesystem->setVisibility($key, 'private');
            $checksum = hash_file('sha256', $file->getRealPath());

            if (! is_string($checksum)) {
                throw new RuntimeException('Identity evidence checksum could not be calculated.');
            }
        } catch (Throwable $exception) {
            try {
                $filesystem->delete($key);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        return [
            'disk' => $disk,
            'object_key' => $key,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
            'checksum_sha256' => $checksum,
        ];
    }

    private function match(
        IdentityEvidence $identityDocument,
        IdentityEvidence $initialSelfie,
    ): IdentityMatchResult {
        try {
            return $this->matcher->compare($identityDocument, $initialSelfie);
        } catch (Throwable) {
            return IdentityMatchResult::error();
        }
    }
}
