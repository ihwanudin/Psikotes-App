<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Models\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DirectPublicOrderFixture
{
    /**
     * @param  array<string, mixed>|null  $orderMetadata
     * @return array{
     *     branch: Branch,
     *     participant: Participant,
     *     package_id: int,
     *     assessment_case_id: int,
     *     order: Order,
     *     entitlements: array{ist: Entitlement, dass21: Entitlement}
     * }
     */
    public static function create(
        string $paymentMethodCode,
        int $amount,
        ?string $gatewayReference = null,
        ?string $proofObjectKey = null,
        ?array $orderMetadata = null,
        bool $paymentMethodActive = true,
        ?CarbonInterface $expiresAt = null,
    ): array {
        $suffix = Str::upper(Str::random(8));
        $branch = Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Cabang {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
        $packageId = DB::table('packages')->insertGetId([
            'code' => "PKG-{$suffix}",
            'name' => "Paket {$suffix}",
            'amount' => $amount,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $packageId, 'test_type' => 'ist', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $packageId, 'test_type' => 'dass21', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $packageId,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => "Peserta {$suffix}",
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
        ]);
        $paymentMethod = PaymentMethod::query()->where('code', $paymentMethodCode)->first();

        if ($paymentMethod === null) {
            $paymentMethod = new PaymentMethod;
            $paymentMethod->forceFill([
                'code' => $paymentMethodCode,
                'display_name' => Str::headline($paymentMethodCode),
                'is_active' => $paymentMethodActive,
            ]);
            $paymentMethod->save();
        }

        $orderPublicId = (string) Str::ulid();
        $caseId = DB::table('assessment_cases')->insertGetId([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $packageId,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => $participant->intended_field,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $caseId,
            'payment_method_id' => $paymentMethod->id,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => 'IDR',
            'gateway_ref' => $gatewayReference,
            'proof_object_key' => $proofObjectKey,
            'metadata' => $orderMetadata,
            'expires_at' => $expiresAt,
        ]);
        $entitlements = [];

        foreach (['ist', 'dass21'] as $testType) {
            $entitlements[$testType] = Entitlement::query()->create([
                'participant_id' => $participant->id,
                'order_id' => $order->id,
                'assessment_case_id' => $testType === 'dass21' ? null : $caseId,
                'test_type' => $testType,
                'status' => 'locked',
            ]);
        }

        return [
            'branch' => $branch,
            'participant' => $participant,
            'package_id' => $packageId,
            'assessment_case_id' => $caseId,
            'order' => $order,
            'entitlements' => $entitlements,
        ];
    }
}
