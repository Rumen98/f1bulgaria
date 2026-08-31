<?php

declare(strict_types=1);

namespace App\Services\Telegram\Formatters;

use App\Services\Telegram\TelegramText;
use Illuminate\Support\Collection;

/**
 * Постовете на Хронометъра в канала: старт на седмичното предизвикателство
 * (четвъртък) и резултатите му (понеделник). Три реални имена с времена са
 * социално доказателство на автопилот — пише се веднъж, работи всеки уикенд.
 */
class GameChallengeFormatter
{
    /** Стартът на предизвикателството. */
    public function challenge(string $trackName, string $url): string
    {
        return implode("\n", [
            '🏁 <b>Пистата на седмицата: '.TelegramText::escape($trackName).'</b>',
            '',
            'Този уикенд Ф1 кара тук — а ти колко бързо можеш?',
            'Запиши обиколка в Хронометъра и влез в седмичната класация.',
            '',
            '🎮 '.TelegramText::escape($url),
        ]);
    }

    /**
     * Резултатите: топ 3 + линк за дуел срещу победителя.
     *
     * @param  Collection<int, array{name: string, lap_ms: int, user_id: int, has_ghost: bool}>  $top
     */
    public function results(string $trackName, string $slug, Collection $top, string $gameUrl): string
    {
        $medals = ['🥇', '🥈', '🥉'];
        $lines = [
            '🏆 <b>Хронометърът: резултати от '.TelegramText::escape($trackName).'</b>',
            '',
        ];

        foreach ($top->take(3)->values() as $i => $row) {
            $lines[] = sprintf(
                '%s %s — <b>%s</b>',
                $medals[$i] ?? '·',
                TelegramText::escape($row['name']),
                $this->formatLap($row['lap_ms']),
            );
        }

        $winner = $top->first();
        $lines[] = '';
        if ($winner !== null && ($winner['has_ghost'] ?? false)) {
            $lines[] = '👻 Дуел срещу победителя: '.TelegramText::escape(
                $gameUrl.'?track='.urlencode($slug).'&rival='.$winner['user_id']
            );
        } else {
            $lines[] = '🎮 '.TelegramText::escape($gameUrl);
        }

        return implode("\n", $lines);
    }

    private function formatLap(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        $seconds = ($ms % 60000) / 1000;

        return sprintf('%d:%06.3f', $minutes, $seconds);
    }
}
