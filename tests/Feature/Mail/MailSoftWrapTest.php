<?php

declare(strict_types=1);

/**
 * Изречение, пренесено на нов ред вътре в параграф, стига до пощата като
 * истински `\n` в HTML-а. По стандарт клиентът го третира като интервал —
 * но не всеки го прави.
 *
 * Реален случай (05.09.2026): едно и също писмо се четеше нормално на
 * iPhone 13 и слепено на iPhone 17 („…колите са напистата…"). Различен
 * рендерер, различно поведение при `\n` в параграф.
 *
 * Затова всеки параграф стои на един ред. Тестът пази правилото за ВСИЧКИ
 * писма, включително тези, които още не са написани.
 */
function mailTemplates(): array
{
    $dir = resource_path('views/mail');
    $found = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}

/** Блокови конструкции, които не са част от параграф. */
function isBlockLine(string $line): bool
{
    $t = trim($line);

    if ($t === '') {
        return true;
    }

    // Заглавия, Blade директиви и коментари, таблици, цитати, HTML, код.
    foreach (['#', '@', '{{--', '|', '>', '<', '!', '`'] as $prefix) {
        if (str_starts_with($t, $prefix)) {
            return true;
        }
    }

    // Списък е „* " / „- " / „+ " СЪС интервал. „**получер**" в началото на
    // ред не е списък и трябва да се слепва — точно този случай пропусна
    // първата версия на проверката.
    foreach (['* ', '- ', '+ '] as $bullet) {
        if (str_starts_with($t, $bullet)) {
            return true;
        }
    }

    return in_array($t, ['---', '***', '___'], strict: true);
}

it('нито едно писмо няма пренесено изречение вътре в параграф', function () {
    $offenders = [];

    foreach (mailTemplates() as $path) {
        $lines = explode("\n", (string) file_get_contents($path));
        $inFence = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with(trim($line), '```')) {
                $inFence = ! $inFence;

                continue;
            }

            if ($inFence || ! isset($lines[$i + 1])) {
                continue;
            }

            $next = $lines[$i + 1];

            if (! isBlockLine($line) && ! isBlockLine($next) && ! str_starts_with(trim($next), '```')) {
                $offenders[] = basename($path).':'.($i + 1).' → '.mb_substr(trim($line), -40);
            }
        }
    }

    expect($offenders)->toBe([], 'Тези редове пренасят изречение вътре в параграф. Слепи ги на един ред — '
        .'иначе HTML-ът стига до пощата с `\\n` в параграфа и някои клиенти '
        ."(видяно на iPhone 17) слепват думите:\n- ".implode("\n- ", $offenders));
});

it('намира писмата, които пази — иначе тестът е празен', function () {
    // Без това тестът горе би минавал победоносно и ако папката се преименува.
    expect(mailTemplates())->not->toBeEmpty()
        ->and(count(mailTemplates()))->toBeGreaterThanOrEqual(10);
});
