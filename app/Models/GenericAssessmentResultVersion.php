<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $assessment_participant_id
 * @property string $assessment_attempt_id
 * @property int $result_version
 * @property string|null $supersedes_id
 * @property float $iq
 * @property string $iq_canonical
 * @property string $engine_version
 * @property CarbonInterface $completed_at
 * @property string $finality
 * @property CarbonInterface|null $revoked_at
 * @property string $result_checksum
 * @property CarbonInterface $created_at
 */
final class GenericAssessmentResultVersion extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'assessment_participant_id' => 'integer',
            'result_version' => 'integer',
            'iq' => 'float',
            'completed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
