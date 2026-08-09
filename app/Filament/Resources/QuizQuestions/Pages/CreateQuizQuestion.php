<?php

declare(strict_types=1);

namespace App\Filament\Resources\QuizQuestions\Pages;

use App\Filament\Resources\QuizQuestions\QuizQuestionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuizQuestion extends CreateRecord
{
    protected static string $resource = QuizQuestionResource::class;
}
