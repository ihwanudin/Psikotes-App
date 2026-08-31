<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Policies\FundingPolicyPolicy;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateFundingPolicy
{
    public function __construct(private RlsContextRunner $runner, private FundingPolicyPolicy $policy) {}

    /** @param array<string, mixed> $input */
    public function forOrganization(Admin $admin, int $organizationId, array $input): Branch
    {
        $this->authorize($admin);

        return $this->runner->runAsService(fn (): Branch => DB::transaction(function () use ($admin, $organizationId, $input): Branch {
            $this->authorizePersistedAdmin($admin);
            $values = $this->validate($input, false);
            $organization = Branch::query()->lockForUpdate()->findOrFail($organizationId);
            $this->saveWithAudit($admin, $organization, $organization->id, $values);

            return $organization;
        }));
    }

    /** @param array<string, mixed> $input */
    public function forSource(Admin $admin, int $organizationId, int $sourceId, array $input): IntegrationSource
    {
        $this->authorize($admin);

        return $this->runner->runAsService(fn (): IntegrationSource => DB::transaction(function () use ($admin, $organizationId, $sourceId, $input): IntegrationSource {
            $this->authorizePersistedAdmin($admin);
            $values = $this->validate($input, true);
            // Reservation must use the same order: organization, client, source, then attempts.
            $organization = Branch::query()->lockForUpdate()->findOrFail($organizationId);
            $client = IntegrationClient::query()->where('organization_id', $organization->id)
                ->whereHas('sources', fn ($query) => $query->whereKey($sourceId))
                ->lockForUpdate()->firstOrFail();
            $source = IntegrationSource::query()->where('integration_client_id', $client->id)
                ->lockForUpdate()->findOrFail($sourceId);
            $this->saveWithAudit($admin, $source, $organization->id, $values);

            return $source;
        }));
    }

    private function authorize(Admin $admin): void
    {
        if (! $this->policy->update($admin)) {
            throw new AuthorizationException('Pengaturan pembayar hanya boleh diubah admin ONCAM yang berwenang.');
        }
    }

    private function authorizePersistedAdmin(Admin $admin): void
    {
        $persisted = Admin::query()->lockForUpdate()->find($admin->id);
        if ($persisted === null) {
            throw new AuthorizationException('Administrator tidak lagi memiliki akses.');
        }
        $this->authorize($persisted);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{allowed_payer_types: list<string>, locked_payer_type?: string|null}
     */
    private function validate(array $input, bool $source): array
    {
        $keys = $source ? ['allowed_payer_types', 'locked_payer_type'] : ['allowed_payer_types'];
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys, array_keys($input)) !== []) {
            throw ValidationException::withMessages(['policy' => 'Kirim hanya seluruh field kebijakan pembayar yang diperlukan.']);
        }

        $allowed = $input['allowed_payer_types'];
        $known = array_column(PayerType::cases(), 'value');
        if (! is_array($allowed) || ! array_is_list($allowed) || count($allowed) > count($known)) {
            throw ValidationException::withMessages(['allowed_payer_types' => 'Pilihan pembayar harus berupa daftar yang valid.']);
        }
        foreach ($allowed as $payer) {
            if (! is_string($payer) || ! in_array($payer, $known, true)) {
                throw ValidationException::withMessages(['allowed_payer_types' => 'Pilihan pembayar tidak dikenal.']);
            }
        }
        if (count(array_unique($allowed)) !== count($allowed)) {
            throw ValidationException::withMessages(['allowed_payer_types' => 'Pilihan pembayar tidak boleh berulang.']);
        }

        $values = ['allowed_payer_types' => array_values(array_intersect($known, $allowed))];
        if ($source) {
            $locked = $input['locked_payer_type'];
            if ($locked !== null && (! is_string($locked) || ! in_array($locked, $allowed, true))) {
                throw ValidationException::withMessages(['locked_payer_type' => 'Pembayar terkunci harus termasuk pilihan yang diizinkan.']);
            }
            $values['locked_payer_type'] = $locked;
        }

        return $values;
    }

    /** @param array{allowed_payer_types: list<string>, locked_payer_type?: string|null} $values */
    private function saveWithAudit(Admin $admin, Branch|IntegrationSource $target, int $organizationId, array $values): void
    {
        $previous = $target->only(array_keys($values));
        if ($previous === $values) {
            return;
        }
        $target->fill($values)->save();
        $at = now();
        DB::table('audit_logs')->insert([
            'branch_id' => $organizationId,
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'action' => 'funding_policy.updated',
            'subject_type' => $target::class,
            'subject_id' => (string) $target->id,
            'context' => json_encode(['from' => $previous, 'to' => $values], JSON_THROW_ON_ERROR),
            'occurred_at' => $at,
            'expires_at' => $at->copy()->addYears(2),
        ]);
    }
}
