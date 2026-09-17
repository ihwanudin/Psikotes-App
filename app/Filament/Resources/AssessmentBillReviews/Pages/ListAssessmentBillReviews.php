<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentBillReviews\Pages;

use App\Filament\Resources\AssessmentBillReviews\AssessmentBillReviewResource;
use Filament\Resources\Pages\ListRecords;

final class ListAssessmentBillReviews extends ListRecords
{
    protected static string $resource = AssessmentBillReviewResource::class;
}
