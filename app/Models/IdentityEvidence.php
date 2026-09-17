<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $participant_id
 * @property string $type
 * @property string $disk
 * @property string $object_key
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $checksum_sha256
 */
#[Fillable([
    'public_id',
    'participant_id',
    'type',
    'disk',
    'object_key',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'checksum_sha256',
])]
final class IdentityEvidence extends Model
{
    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }
}
