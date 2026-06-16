<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Статични правни/информационни страници (placeholder съдържание за launch).
 * Текстът се финализира преди продукция.
 */
class StaticPageController extends Controller
{
    public function privacy(): Response
    {
        return $this->page('Политика за поверителност', 'Как обработваме и защитаваме личните ви данни.');
    }

    public function terms(): Response
    {
        return $this->page('Условия за ползване', 'Правилата за използване на платформата.');
    }

    public function contact(): Response
    {
        return $this->page('Контакт', 'Свържете се с екипа на F1 България.');
    }

    private function page(string $title, string $intro): Response
    {
        return Inertia::render('Static/Page', [
            'title' => $title,
            'intro' => $intro,
        ]);
    }
}
