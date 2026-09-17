<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\RequiresRlsContext;
use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AssessmentParticipantExportController extends Controller implements RequiresRlsContext
{
    private const array STATUSES = ['PROVISIONED', 'READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED', 'REVOKED', 'VOID'];

    public function __invoke(Request $request): StreamedResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ViewParticipants)) {
            throw new AuthorizationException;
        }

        $filters = $this->filters($request);
        $query = AssessmentParticipant::query()->with('package:id,code');
        if (! in_array($admin->role, [AdminRole::SuperAdmin, AdminRole::Psychologist], true)) {
            $query->where('organization_id', $admin->branch_id);
        }
        $query
            ->when($filters['status'], fn (Builder $query, string $status): Builder => $query->where('assessment_status', $status))
            ->when($filters['packageCode'], fn (Builder $query, string $code): Builder => $query->whereHas('package', fn (Builder $package): Builder => $package->where('code', $code)))
            ->when($filters['round'], fn (Builder $query, string $round): Builder => $query->where('assessment_round_id', $round))
            ->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, [
                'participant_id', 'external_candidate_id', 'external_process_id', 'assessment_round_id',
                'package_code', 'assessment_status', 'recommendation', 'result_version', 'finalized_at',
            ]);
            $query->chunkById(200, function ($rows) use ($stream): void {
                foreach ($rows as $row) {
                    fputcsv($stream, array_map($this->safeCell(...), [
                        (string) $row->participant_id,
                        $row->external_candidate_id,
                        $row->external_process_id,
                        $row->assessment_round_id,
                        $row->package->code,
                        $row->assessment_status,
                        $row->recommendation,
                        (string) $row->result_version,
                        $row->finalized_at?->toISOString(),
                    ]));
                }
            });
            fclose($stream);
        }, 'assessment-participants-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @return array{status:?string,packageCode:?string,round:?string} */
    private function filters(Request $request): array
    {
        if (array_diff(array_keys($request->query()), ['status', 'packageCode', 'round']) !== []) {
            throw new AuthorizationException('Unsupported export filter.');
        }
        $status = $request->query('status');
        if ($status !== null && (! is_string($status) || ! in_array($status, self::STATUSES, true))) {
            throw new AuthorizationException('Unsupported export status.');
        }
        $package = $this->identifier($request->query('packageCode'));
        $round = $this->identifier($request->query('round'));

        return ['status' => $status, 'packageCode' => $package, 'round' => $round];
    }

    private function identifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || strlen($value) > 100 || ! preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
            throw new AuthorizationException('Unsupported export filter.');
        }

        return $value;
    }

    private function safeCell(mixed $value): string
    {
        $cell = $value === null ? '' : (string) $value;

        return preg_match('/^[=+\-@]/', $cell) ? "'".$cell : $cell;
    }
}
