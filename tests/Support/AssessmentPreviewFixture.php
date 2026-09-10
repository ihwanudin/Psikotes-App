<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Fresh, unbilled checkout attempts; only for guarded test databases. */
final class AssessmentPreviewFixture
{
    public static function create(?array $identity = null, int $amount = 100): array
    {
        $fixture = AssessmentBillingFixture::create('organization', $identity);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->delete();
        DB::table('assessment_charges')->where('id', $fixture['charge'])->delete();
        DB::table('branches')->where('id', $fixture['organization'])->update(['is_active' => true, 'status' => 'ACTIVE',
            'allowed_payer_types' => '["self","organization"]']);
        $attempt = DB::table('assessment_participants')->where('id', $fixture['attempt'])->first();
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'metadata' => '{"checkout_contract_version":"checkout-v2"}']);
        DB::table('integration_clients')->where('id', $attempt->integration_client_id)->update(['enabled' => true]);
        $code = DB::table('packages')->where('id', $fixture['package'])->value('code');
        DB::table('packages')->where('id', $fixture['package'])->update(['amount' => $amount, 'consultation_amount' => 30, 'is_active' => true]);
        DB::table('package_items')->insert([
            ['package_id' => $fixture['package'], 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $source = DB::table('integration_sources')->insertGetId(['integration_client_id' => $attempt->integration_client_id,
            'source_system' => $attempt->source_system, 'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$code], JSON_THROW_ON_ERROR), 'allowed_funding_modes' => '[]',
            'allowed_payer_types' => '["self","organization"]', 'status' => 'ACTIVE']);

        return [...$fixture, 'source' => $source, 'client' => $attempt->integration_client_id];
    }

    public static function selection(array $fixture, bool $consultation = false): array
    {
        return ['assessmentParticipantId' => $fixture['attempt'], 'consultationRequested' => $consultation];
    }
}
