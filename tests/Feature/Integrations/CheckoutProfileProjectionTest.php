<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Data\Integrations\CheckoutProfile;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutProfileMapper;
use DateTimeImmutable;
use DomainException;
use Error;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class CheckoutProfileProjectionTest extends OrganizationPaymentTestCase
{
    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['full_name' => 'Synthetic Participant', 'birth_date' => '2000-02-29', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'email' => 'synthetic@example.test',
            'phone' => '+62 800-123456'];
    }

    /** @param array<string, mixed> $attributes */
    private function project(array $attributes): CheckoutProfile
    {
        return app(CheckoutProfileMapper::class)->map((new Participant)->setRawAttributes($attributes),
            new DateTimeImmutable('2026-09-04T00:00:00+07:00'));
    }

    public function test_complete_profile_is_locked_and_projection_is_readonly_without_queries(): void
    {
        $participant = (new Participant)->setRawAttributes($this->valid());
        $before = $participant->getAttributes();
        $this->assertNull(app(RlsContextRunner::class)->current());
        DB::enableQueryLog();
        $dto = app(CheckoutProfileMapper::class)->map($participant, new DateTimeImmutable('2026-09-04'));
        $this->assertSame([], DB::getQueryLog());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($before, $participant->getAttributes());
        $fields = $dto->toArray()['profile'];
        $this->assertSame(['fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'email', 'phone'], array_column($fields, 'key'));
        $this->assertSame(array_fill(0, 7, 'locked'), array_column($fields, 'state'));
        $this->assertSame(['Synthetic Participant', '2000-02-29', 'Perempuan', 'SMA_SMK', 'Kaigo / perawatan',
            'synthetic@example.test', '+62 800-123456'], array_column($fields, 'displayValue'));
    }

    public function test_sql_null_is_missing_and_email_optional_without_defaults(): void
    {
        $fields = $this->project(array_fill_keys(array_keys($this->valid()), null))->toArray()['profile'];
        foreach ($fields as $field) {
            $this->assertSame('missing', $field['state']);
            $this->assertSame($field['key'] !== 'email', $field['required']);
            $this->assertArrayNotHasKey('displayValue', $field);
            $this->assertArrayNotHasKey('input', $field);
        }
        $this->assertStringNotContainsString('UMUM', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function test_unloaded_column_is_not_treated_as_a_known_sql_null(): void
    {
        $attributes = $this->valid();
        unset($attributes['email']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CHECKOUT_PROFILE_UNAVAILABLE');
        $this->project($attributes);
    }

    public function test_each_partial_null_leaves_the_other_existing_fields_locked(): void
    {
        foreach (array_keys($this->valid()) as $index => $column) {
            $fields = $this->project([...$this->valid(), $column => null])->toArray()['profile'];
            $this->assertSame('missing', $fields[$index]['state']);
            $this->assertArrayNotHasKey('displayValue', $fields[$index]);
            $this->assertSame(6, count(array_filter($fields, static fn (array $field): bool => $field['state'] === 'locked')));
        }
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_nonnull_values_fail_closed_without_echoing_payload(string $column, mixed $value): void
    {
        try {
            $this->project([...$this->valid(), $column => $value]);
            $this->fail('Invalid persisted profile must not become an editable missing field.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PROFILE_UNAVAILABLE', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValues(): iterable
    {
        foreach (['full_name', 'birth_date', 'gender', 'education_level', 'intended_field', 'email', 'phone'] as $field) {
            yield $field.' blank' => [$field, '   '];
            yield $field.' array' => [$field, ['untrusted' => 'sentinel']];
            yield $field.' boolean' => [$field, true];
        }
        yield 'short name' => ['full_name', 'A'];
        yield 'long name' => ['full_name', str_repeat('A', 201)];
        yield 'date rollover' => ['birth_date', '2000-02-30'];
        yield 'timestamp rollover' => ['birth_date', '2000-02-30 00:00:00'];
        yield 'today' => ['birth_date', '2026-09-04'];
        yield 'future' => ['birth_date', '2026-09-05'];
        yield 'unknown date representation' => ['birth_date', 'yesterday'];
        yield 'unmapped ingress gender' => ['gender', 'FEMALE'];
        yield 'unknown gender' => ['gender', 'unknown'];
        yield 'long education' => ['education_level', str_repeat('A', 65)];
        yield 'unknown field' => ['intended_field', 'OTHER'];
        yield 'invalid email' => ['email', 'private-sentinel-without-address'];
        yield 'long email' => ['email', str_repeat('a', 250).'@example.test'];
        yield 'numeric phone' => ['phone', 62800123456];
        yield 'invalid phone' => ['phone', 'private-sentinel'];
        yield 'long phone' => ['phone', str_repeat('1', 33)];
    }

    #[DataProvider('intendedFields')]
    public function test_existing_intended_field_enum_is_mapped_without_fallback(string $value, string $label): void
    {
        $field = $this->project([...$this->valid(), 'intended_field' => $value])->toArray()['profile'][4];
        $this->assertSame('locked', $field['state']);
        $this->assertSame($label, $field['displayValue']);
    }

    /** @return iterable<array{string, string}> */
    public static function intendedFields(): iterable
    {
        yield ['KAIGO', 'Kaigo / perawatan'];
        yield ['KENSETSU', 'Kensetsu / konstruksi'];
        yield ['NOUGYOU', 'Nougyou / pertanian'];
        yield ['SEIZOU', 'Seizou / manufaktur'];
        yield ['GAISHOKU', 'Gaishoku / layanan makanan'];
        yield ['UMUM', 'Umum'];
    }

    public function test_checkout_validator_accepted_edge_values_are_not_restricted_by_public_registration_rules(): void
    {
        $profile = ['fullName' => '<b>Synthetic</b>', 'birthDate' => '2000-02-29', 'gender' => 'MALE',
            'educationLevel' => 'S', 'intendedField' => 'UMUM', 'email' => null, 'phone' => '1234567)'];
        $rules = array_filter((new ProvisionCheckoutParticipantRequest)->rules(),
            static fn (string $key): bool => $key === 'profile' || str_starts_with($key, 'profile.'), ARRAY_FILTER_USE_KEY);
        $this->assertFalse(Validator::make(['profile' => $profile], $rules)->fails());
        $dto = $this->project([...$this->valid(), 'full_name' => $profile['fullName'], 'gender' => 'male',
            'education_level' => 'S', 'intended_field' => 'UMUM', 'email' => null, 'phone' => $profile['phone']]);
        $fields = $dto->toArray()['profile'];
        $this->assertSame('<b>Synthetic</b>', $fields[0]['displayValue']); // Plain text data, never an HTML renderer.
        $this->assertSame('Laki-laki', $fields[2]['displayValue']);
        $this->assertSame('S', $fields[3]['displayValue']);
        $this->assertSame('1234567)', $fields[6]['displayValue']);
    }

    public function test_model_sql_timestamp_and_timezone_offsets_do_not_shift_birth_date(): void
    {
        $participant = (new Participant)->setRawAttributes($this->valid());
        $participant->birth_date = new DateTimeImmutable('2000-02-29T00:30:00+14:00');
        $this->assertSame('2000-02-29 00:30:00', $participant->getAttributes()['birth_date']);
        foreach (['UTC', 'America/Los_Angeles', 'Asia/Bangkok'] as $timezone) {
            config()->set('app.timezone', $timezone);
            $dto = app(CheckoutProfileMapper::class)->map($participant, new DateTimeImmutable('2026-09-04'));
            $this->assertSame('2000-02-29', $dto->toArray()['profile'][1]['displayValue']);
        }
    }

    public function test_recursive_allowlist_excludes_every_model_relation_and_metadata_sentinel(): void
    {
        $extras = array_fill_keys(['id', 'branch_id', 'package_id', 'external_candidate_id', 'source_system',
            'registration_token', 'registration_payload_hash', 'test_number', 'clinical_result', 'purge_after'], 'EXCLUDED_SENTINEL');
        $participant = (new Participant)->setRawAttributes([...$this->valid(), ...$extras,
            'metadata' => ['secret' => 'EXCLUDED_SENTINEL', 'invoice_url' => 'EXCLUDED_SENTINEL']]);
        $participant->setRelation('identityEvidence', collect([['object_key' => 'EXCLUDED_SENTINEL']]));
        $participant->setRelation('branch', collect([['other_participant' => 'EXCLUDED_SENTINEL']]));
        $dto = app(CheckoutProfileMapper::class)->map($participant, new DateTimeImmutable('2026-09-04'));
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('EXCLUDED_SENTINEL', $json);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['profile'], array_keys($payload));
        foreach ($payload['profile'] as $field) {
            $this->assertSame(['key', 'label', 'state', 'required', 'displayValue'], array_keys($field));
            foreach ($field as $value) {
                $this->assertTrue(is_string($value) || is_bool($value));
            }
        }
        $copy = $dto->toArray();
        $copy['profile'][0]['displayValue'] = 'changed copy';
        $this->assertSame('Synthetic Participant', $dto->toArray()['profile'][0]['displayValue']);
        $this->expectException(Error::class);
        $dto->fullName = 'cannot mutate readonly DTO';
    }
}
