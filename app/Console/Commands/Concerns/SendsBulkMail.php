<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Изпраща бюлетините СИНХРОННО, не през опашката.
 *
 * ЗАЩО: командите маркират кръга в `newsletter_sends` ПРЕДИ да пуснат
 * писмата. С `->queue()` и мъртъв worker това значи „изпратено" в базата и
 * нула писма в пощите — тихо, без грешка, без втори опит, защото
 * дедупликацията после отказва повторение. Точно това се случи на
 * 06.09.2026: worker-ът беше спрял и рекапът щеше да изчезне в нищото.
 *
 * При 40 получателя опашката не купува нищо — четиридесет писма отнемат
 * около половин минута — а носи цял клас тихи откази. Синхронното пращане
 * ги маха: приключи ли командата, писмата са тръгнали.
 *
 * Всеки получател е в собствен try/catch. Без него един лош адрес би
 * прекъснал цикъла и останалите нямаше да получат нищо — точно предимството
 * на опашката, което не бива да губим.
 */
trait SendsBulkMail
{
    protected int $mailFailures = 0;

    protected int $mailSent = 0;

    /**
     * @param  mixed  $to  получател (User модел или имейл низ)
     */
    protected function sendMail(mixed $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);
            $this->mailSent++;

            return true;
        } catch (Throwable $e) {
            $this->mailFailures++;

            $address = is_string($to) ? $to : ($to->email ?? '?');
            Log::warning("Писмо до {$address} се провали: ".$e->getMessage());

            return false;
        }
    }

    /** Кратък отчет накрая — иначе провалите остават само в лога. */
    protected function reportMailOutcome(): void
    {
        $this->info("Изпратени писма: {$this->mailSent}");

        if ($this->mailFailures > 0) {
            $this->warn("Неуспешни: {$this->mailFailures} (виж laravel.log)");
        }
    }
}
