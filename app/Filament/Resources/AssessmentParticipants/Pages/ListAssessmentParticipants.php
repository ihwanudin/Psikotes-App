<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentParticipants\Pages;

use App\Filament\Resources\AssessmentParticipants\AssessmentParticipantResource;
use Filament\Resources\Pages\ListRecords;

final class ListAssessmentParticipants extends ListRecords
{
    protected static string $resource = AssessmentParticipantResource::class;
}
