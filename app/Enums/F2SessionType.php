<?php

declare(strict_types=1);

namespace App\Enums;

enum F2SessionType: string
{
    case Practice = 'practice';
    case Qualifying = 'qualifying';
    case QualifyingGroupA = 'qualifying_group_a';
    case QualifyingGroupB = 'qualifying_group_b';
    case SprintRace = 'sprint_race';
    case FeatureRace = 'feature_race';

    public function label(): string
    {
        return match ($this) {
            self::Practice => 'Свободна тренировка',
            self::Qualifying => 'Квалификация',
            self::QualifyingGroupA => 'Квалификация, група А',
            self::QualifyingGroupB => 'Квалификация, група Б',
            self::SprintRace => 'Спринт',
            self::FeatureRace => 'Главно състезание',
        };
    }

    /**
     * Съответствие с кода на сесията в официалното API.
     *
     * Групите А и Б съществуват само в Монако, където квалификацията се дели
     * на две. Държим ги като отделни стойности, защото `f2_race_sessions` е
     * уникална по (кръг, вид) — една обща „квалификация" би изгубила едната.
     */
    public static function fromApiCode(string $code): ?self
    {
        return match (strtolower($code)) {
            'p' => self::Practice,
            'q' => self::Qualifying,
            'q1' => self::QualifyingGroupA,
            'q2' => self::QualifyingGroupB,
            'r1' => self::SprintRace,
            'r' => self::FeatureRace,
            default => null,
        };
    }

    /**
     * Носи ли сесията шампионатни точки.
     */
    public function isRace(): bool
    {
        return in_array($this, [self::SprintRace, self::FeatureRace], strict: true);
    }

    /**
     * Номерът на сесията, който API-то очаква като `session` параметър.
     * null = параметърът се пропуска (обикновена квалификация).
     */
    public function apiSessionNumber(): ?int
    {
        return match ($this) {
            self::Practice => 0,
            self::Qualifying => null,
            self::QualifyingGroupA, self::SprintRace => 1,
            self::QualifyingGroupB, self::FeatureRace => 2,
        };
    }

    /**
     * Отрязъкът в URL-а на страницата за сесията (`/f2/races/{race}/{session}`).
     *
     * `sprint` и `feature` са наследени и НЕ бива да се преименуват — външни
     * линкове и вече публикувани постове в канала сочат към тях.
     */
    public function urlSegment(): string
    {
        return match ($this) {
            self::Practice => 'practice',
            self::Qualifying => 'qualifying',
            self::QualifyingGroupA => 'qualifying-a',
            self::QualifyingGroupB => 'qualifying-b',
            self::SprintRace => 'sprint',
            self::FeatureRace => 'feature',
        };
    }

    public static function fromUrlSegment(string $segment): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->urlSegment() === $segment) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Подредба в рамките на уикенда — за показване и за реда на публикуване.
     */
    public function order(): int
    {
        return match ($this) {
            self::Practice => 1,
            self::Qualifying, self::QualifyingGroupA => 2,
            self::QualifyingGroupB => 3,
            self::SprintRace => 4,
            self::FeatureRace => 5,
        };
    }

    /**
     * Съответният вид публикация в канала.
     */
    public function channelPostKind(): ChannelPostKind
    {
        return match ($this) {
            self::Practice => ChannelPostKind::F2Practice,
            self::Qualifying, self::QualifyingGroupA, self::QualifyingGroupB => ChannelPostKind::F2Qualifying,
            self::SprintRace => ChannelPostKind::F2SprintRace,
            self::FeatureRace => ChannelPostKind::F2FeatureRace,
        };
    }
}
