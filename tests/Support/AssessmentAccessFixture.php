<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Services\Payments\AssessmentPriceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic settled attempt only; caller owns isolated DB and service context. */
final class AssessmentAccessFixture
{
    public static function create(string $type = 'ist', ?array $identity = null): array
    {
        $f = AssessmentBillingFixture::create('organization', $identity);
        DB::table('assessment_participants')->where('id', $f['attempt'])->update([
            'assessment_status' => 'READY', 'metadata' => '{"checkout_contract_version":"checkout-v2"}']);
        DB::table('packages')->where('id', $f['package'])->update(['is_active' => true]);
        $companionType = $type === 'dass21' ? 'ist' : 'dass21';
        DB::table('package_items')->insert([
            ['package_id' => $f['package'], 'test_type' => $type, 'sort_order' => 1],
            ['package_id' => $f['package'], 'test_type' => $companionType, 'sort_order' => 2],
        ]);
        $snapshot = app(AssessmentPriceSnapshot::class)->capture(TestPackage::with('items')->findOrFail($f['package']), false);
        AssessmentCharge::findOrFail($f['charge'])->update(['price_snapshot' => $snapshot]);
        DB::table('assessment_bills')->where('id', $f['bill'])->update(['status' => 'paid', 'paid_at' => now()]);
        $item = DB::table('assessment_bill_items')->insertGetId([...AssessmentBillingFixture::item($f), 'settled_at' => now()]);
        $entitlement = DB::table('assessment_entitlements')->insertGetId([
            ...AssessmentBillingFixture::entitlement($f), 'test_type' => $type, 'status' => 'ready', 'ready_at' => now()]);
        DB::table('assessment_entitlements')->insert([
            ...AssessmentBillingFixture::entitlement($f), 'test_type' => $companionType,
            'status' => 'ready', 'ready_at' => now(),
        ]);
        if ($identity === null || ! isset($identity['participant'])) {
            foreach (['psychotest', 'dass'] as $consentType) {
                $document = ConsentDocument::for($consentType);
                DB::table('consent_records')->insert(['participant_id' => $f['participant'], 'consent_type' => $consentType,
                    'status' => 'accepted', 'document_version' => $document->version, 'document_hash' => $document->hash, 'consented_at' => now()]);
            }
            foreach (['identity_document', 'initial_selfie'] as $evidenceType) {
                $id = (string) Str::ulid();
                DB::table('identity_evidence')->insert(['public_id' => $id, 'participant_id' => $f['participant'], 'type' => $evidenceType,
                    'disk' => 'local', 'object_key' => 'synthetic/'.$id, 'mime_type' => 'image/jpeg', 'size_bytes' => 100,
                    'width' => 10, 'height' => 10, 'checksum_sha256' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('identity_verifications')->insert(['participant_id' => $f['participant'], 'matcher' => 'synthetic',
                'outcome' => 'match', 'manual_status' => 'pending', 'checked_at' => now()]);
        }

        return [...$f, 'item' => $item, 'entitlement' => $entitlement, 'type' => $type];
    }
}
