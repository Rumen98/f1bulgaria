<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Вид на публикацията в канала.
 *
 * Видът носи и сесията, а не само темата, защото едно състезание ражда до
 * седем поста. Заедно със subject-а той образува уникалния ключ в
 * `channel_posts` — това е механизмът, който пази канала от повторни постове
 * при всеки почасов синхрон.
 */
enum ChannelPostKind: string
{
    case F1Fp1 = 'f1_fp1';
    case F1Fp2 = 'f1_fp2';
    case F1Fp3 = 'f1_fp3';
    case F1Qualifying = 'f1_qualifying';
    case F1SprintQuali = 'f1_sprint_quali';
    case F1Sprint = 'f1_sprint';
    case F1Race = 'f1_race';

    case F2Practice = 'f2_practice';
    case F2Qualifying = 'f2_qualifying';
    case F2SprintRace = 'f2_sprint_race';
    case F2FeatureRace = 'f2_feature_race';

    case News = 'news';

    public function label(): string
    {
        return match ($this) {
            self::F1Fp1 => 'Свободна тренировка 1',
            self::F1Fp2 => 'Свободна тренировка 2',
            self::F1Fp3 => 'Свободна тренировка 3',
            self::F1Qualifying => 'Квалификация',
            self::F1SprintQuali => 'Спринт квалификация',
            self::F1Sprint => 'Спринт',
            self::F1Race => 'Състезание',
            self::F2Practice => 'Свободна тренировка',
            self::F2Qualifying => 'Квалификация',
            self::F2SprintRace => 'Спринт',
            self::F2FeatureRace => 'Главно състезание',
            self::News => 'Новина',
        };
    }

    /**
     * Шампионат — за заглавието на поста и за филтри в админа.
     */
    public function series(): ?string
    {
        return match ($this) {
            self::F1Fp1, self::F1Fp2, self::F1Fp3, self::F1Qualifying,
            self::F1SprintQuali, self::F1Sprint, self::F1Race => 'Формула 1',
            self::F2Practice, self::F2Qualifying,
            self::F2SprintRace, self::F2FeatureRace => 'Формула 2',
            self::News => null,
        };
    }

    /**
     * Постове, чиито данни идват от OpenF1 и затова изискват атрибуция по
     * CC BY-NC-SA. Jolpica няма такова изискване.
     */
    public function requiresOpenF1Attribution(): bool
    {
        return in_array($this, [
            self::F1Fp1,
            self::F1Fp2,
            self::F1Fp3,
            self::F1SprintQuali,
        ], strict: true);
    }

    /**
     * Да се показва ли картинка под линка.
     *
     * При новина превюто е самото съдържание — то носи заглавната снимка от
     * og:image и прави поста да изглежда като новина, а не като линк. При
     * класация е обратното: превюто отблъсква резултатите надолу и заема
     * половин екран на телефон.
     */
    public function showsLinkPreview(): bool
    {
        return $this === self::News;
    }

    /**
     * Тренировките са фон, не събитие — пращат се без звук, за да не будят
     * хората в петък сутрин заради четвърто място във FP1.
     */
    public function isSilent(): bool
    {
        return in_array($this, [
            self::F1Fp1,
            self::F1Fp2,
            self::F1Fp3,
            self::F2Practice,
        ], strict: true);
    }

    /**
     * Съответствие с типа сесия в `race_sessions` / OpenF1 класификацията.
     */
    public static function fromF1SessionType(SessionType $type): self
    {
        return match ($type) {
            SessionType::FP1 => self::F1Fp1,
            SessionType::FP2 => self::F1Fp2,
            SessionType::FP3 => self::F1Fp3,
            SessionType::Qualifying => self::F1Qualifying,
            SessionType::SprintQuali => self::F1SprintQuali,
            SessionType::Sprint => self::F1Sprint,
            SessionType::Race => self::F1Race,
        };
    }
}
