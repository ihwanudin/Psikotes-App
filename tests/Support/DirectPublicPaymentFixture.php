<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\TestPackage;
use Illuminate\Support\Str;

final class DirectPublicPaymentFixture
{
    public static function caseFor(Participant $participant, Branch $branch, string $publicId, int $amount): AssessmentCase
    {
        $package = TestPackage::query()->create([
            'code' => 'DIRECT-'.Str::lower($publicId),
            'name' => 'Synthetic direct-public package',
            'amount' => $amount,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $package->items()->createMany([
            ['test_type' => 'ist', 'sort_order' => 1],
            ['test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $participant->forceFill([
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
        ])->save();

        return AssessmentCase::query()->create([
            'public_id' => $publicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => $participant->intended_field,
        ]);
    }
}
