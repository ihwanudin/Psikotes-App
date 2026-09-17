<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\AssessmentBill;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class AssessmentBillProofIdentitySchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void
    {
        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        } finally {
            parent::tearDown();
        }
    }

    private const array COLUMNS = [
        'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_columns_are_nullable_and_model_fillable_casts_are_rolling_compatible(): void
    {
        $this->assertTrue(Schema::hasColumns('assessment_bills', self::COLUMNS));
        $fixture = Fixture::create();
        $row = DB::table('assessment_bills')->find($fixture['bill']);
        foreach (self::COLUMNS as $column) {
            $this->assertNull($row->{$column});
        }

        $bill = AssessmentBill::query()->findOrFail($fixture['bill']);
        $bill->fill([
            'proof_object_key' => $this->key('jpg'),
            'proof_checksum_sha256' => str_repeat('a', 64),
            'proof_mime_type' => 'image/jpeg',
            'proof_size_bytes' => 5_120_000,
            'proof_uploaded_at' => '2026-09-01T12:00:00+00:00',
        ])->save();
        $bill->refresh();

        $this->assertSame(5_120_000, $bill->proof_size_bytes);
        $this->assertInstanceOf(CarbonImmutable::class, $bill->proof_uploaded_at);
        $this->assertSame('2026-09-01T12:00:00+00:00', $bill->proof_uploaded_at->toIso8601String());
        $this->assertSame(str_repeat('a', 64), $bill->proof_checksum_sha256);
        $this->assertSame('image/jpeg', $bill->proof_mime_type);
    }

    public function test_empty_metadata_down_up_roundtrip_preserves_historical_bill_and_defaults(): void
    {
        $fixture = Fixture::create();
        $baseColumns = array_values(array_diff(Schema::getColumnListing('assessment_bills'), self::COLUMNS));
        $before = DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns);

        $this->migration()->down();
        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('assessment_bills', $column));
        }
        $this->assertEquals($before, DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns));

        $this->migration()->up();
        $this->assertEquals($before, DB::table('assessment_bills')->where('id', $fixture['bill'])->first($baseColumns));
        foreach (self::COLUMNS as $column) {
            $this->assertNull(DB::table('assessment_bills')->where('id', $fixture['bill'])->value($column));
        }
    }

    #[DataProvider('metadataValues')]
    public function test_down_refuses_each_populated_metadata_field_without_partial_schema(string $column, mixed $value): void
    {
        $fixture = Fixture::create();
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([$column => $value]);
        $structure = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
        $before = DB::table('assessment_bills')->find($fixture['bill']);

        try {
            $this->migration()->down();
            $this->fail('Rollback must not discard proof identity metadata.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment bill proof identity prevents migration rollback.', $exception->getMessage());
        }

        $this->assertEquals($structure, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertEquals($before, DB::table('assessment_bills')->find($fixture['bill']));
        $this->assertTrue(Schema::hasColumns('assessment_bills', self::COLUMNS));
    }

    public static function metadataValues(): iterable
    {
        yield 'checksum' => ['proof_checksum_sha256', str_repeat('a', 64)];
        yield 'mime' => ['proof_mime_type', 'image/jpeg'];
        yield 'size' => ['proof_size_bytes', 1];
        yield 'uploaded at' => ['proof_uploaded_at', '2026-09-01T12:00:00+00:00'];
    }

    public function test_up_refuses_existing_object_key_before_adding_any_column(): void
    {
        $fixture = Fixture::create();
        $this->migration()->down();
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'proof_object_key' => 'legacy/private-proof.jpg',
        ]);
        $structure = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');

        try {
            $this->migration()->up();
            $this->fail('Existing proof key must not be guessed or backfilled.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment bill proof identity cannot be migrated safely.', $exception->getMessage());
        }

        $this->assertEquals($structure, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertSame('legacy/private-proof.jpg', DB::table('assessment_bills')
            ->where('id', $fixture['bill'])->value('proof_object_key'));
        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('assessment_bills', $column));
        }
    }

    public function test_up_refuses_partial_target_schema_without_adding_remaining_columns(): void
    {
        $this->migration()->down();
        Schema::table('assessment_bills', function (Blueprint $table): void {
            $table->char('proof_checksum_sha256', 64)->nullable();
        });
        $structure = DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');

        try {
            $this->migration()->up();
            $this->fail('A partial target schema must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Assessment bill proof identity cannot be migrated safely.', $exception->getMessage());
        }

        $this->assertEquals($structure, DB::select('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
        $this->assertTrue(Schema::hasColumn('assessment_bills', 'proof_checksum_sha256'));
        foreach (array_slice(self::COLUMNS, 1) as $column) {
            $this->assertFalse(Schema::hasColumn('assessment_bills', $column));
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_01_000200_add_manual_proof_identity_to_assessment_bills.php');
    }

    private function key(string $extension): string
    {
        return 'assessment-bills/ab/'.str_repeat('c', 62).'.'.$extension;
    }
}
