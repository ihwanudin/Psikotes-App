<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Contracts\AssessmentSessionDefinitionAuthority;
use App\Domain\AssessmentSessions\AssessmentSessionDefinitionUnavailable;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionDefinitionCatalog;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;

final class DatabaseAssessmentSessionDefinitionAuthority implements AssessmentSessionDefinitionAuthority
{
    public function __construct(
        private readonly RlsContextRunner $contexts,
    ) {}

    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('Session definitions require the existing service transaction.');
        }
        if ($authorization->instrument !== $instrument) {
            throw new InvalidAssessmentSessionDefinitionCatalog(
                'Session definition authorization instrument is inconsistent.',
            );
        }

        $rows = DB::table('assessment_session_definitions')
            ->where('instrument', $instrument->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(2)
            ->get();
        if ($rows->isEmpty()) {
            throw new AssessmentSessionDefinitionUnavailable(
                'No active session definition is available for this instrument.',
            );
        }
        if ($rows->count() !== 1) {
            throw new InvalidAssessmentSessionDefinitionCatalog(
                'The session definition catalog has ambiguous active history.',
            );
        }

        $row = (array) $rows->sole();

        try {
            $template = $this->decodeTemplate($row, $instrument);
            $template['checksum'] = SessionDefinition::checksumFor($template);

            return SessionDefinition::fromArray($template);
        } catch (InvalidAssessmentSessionDefinitionCatalog $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidAssessmentSessionDefinitionCatalog(
                'The active session definition is invalid.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeTemplate(array $row, GenericAssessmentInstrument $instrument): array
    {
        $encoded = $row['template_payload'] ?? null;
        if (! is_string($encoded)) {
            throw new InvalidAssessmentSessionDefinitionCatalog('Catalog template payload is not encoded JSON.');
        }

        $template = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($template) || array_is_list($template)) {
            throw new InvalidAssessmentSessionDefinitionCatalog('Catalog template payload must be an object.');
        }
        if (($template['seed'] ?? null) !== null) {
            throw new InvalidAssessmentSessionDefinitionCatalog('Catalog templates must not persist a session seed.');
        }
        if (($row['instrument'] ?? null) !== $instrument->value
            || ($row['version'] ?? null) !== ($template['version'] ?? null)
            || ($row['provenance'] ?? null) !== ($template['provenance'] ?? null)
            || ($template['instrument'] ?? null) !== $instrument->value) {
            throw new InvalidAssessmentSessionDefinitionCatalog('Catalog scalar identity disagrees with its payload.');
        }

        $checksum = $row['template_checksum'] ?? null;
        if (! is_string($checksum)
            || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            || ! hash_equals(SessionDefinition::checksumFor($template), $checksum)) {
            throw new InvalidAssessmentSessionDefinitionCatalog('Catalog template checksum is invalid.');
        }

        $candidate = $template;
        $candidate['checksum'] = $checksum;
        SessionDefinition::fromArray($candidate);

        return $template;
    }
}
