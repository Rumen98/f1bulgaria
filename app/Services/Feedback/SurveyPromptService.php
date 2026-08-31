<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Models\User;

/**
 * Решава кога да се покаже картата-подкана за обратна връзка.
 *
 * Правила: акаунтът да е поне на MIN_ACCOUNT_AGE_DAYS дни (мнение от още
 * неразгледан сайт не е полезно) и да няма никакво взаимодействие с анкетата
 * през последните REPROMPT_MONTHS месеца. Всеки ред в survey_responses се
 * брои за взаимодействие — отговор, скриване или доброволно мнение през
 * страницата — за да не досаждаме на човек, който вече ни е писал.
 */
class SurveyPromptService
{
    private const MIN_ACCOUNT_AGE_DAYS = 14;

    public const REPROMPT_MONTHS = 6;

    public function shouldPrompt(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->created_at === null || $user->created_at->gt(now()->subDays(self::MIN_ACCOUNT_AGE_DAYS))) {
            return false;
        }

        return ! $user->surveyResponses()
            ->where('created_at', '>=', now()->subMonths(self::REPROMPT_MONTHS))
            ->exists();
    }
}
