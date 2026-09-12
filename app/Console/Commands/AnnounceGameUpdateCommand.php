<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SendsBulkMail;
use App\Mail\GameUpdateMail;
use App\Models\NewsletterSend;
use App\Services\Newsletter\NewsletterAudience;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

/**
 * Кратко писмо САМО до пробвалите играта: обновихме я, изиграй едно
 * състезание и кажи какво мислиш. Ръчен пуск, без график; идемпотентно
 * през newsletter_sends като останалите еднократни писма.
 */
class AnnounceGameUpdateCommand extends Command
{
    use SendsBulkMail;

    protected $signature = 'padok:announce-game-update
        {--dry-run : Само отчита кой би получил писмо}
        {--force : Праща дори ако това съобщение вече е изпращано}';

    protected $description = 'Изпраща писмото „обновихме играта, изиграй едно състезание" до потребителите, които са я пробвали.';

    /**
     * Уникален slug на тази вълна — държи идемпотентността в newsletter_sends.
     */
    private const MAIL_TYPE = 'game-update-2026-09';

    public function handle(NewsletterAudience $audience): int
    {
        if (! config('features.game')) {
            $this->error('Играта е изключена (FEATURE_GAME). Писмото щеше да води към 404 — не пращам.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->alreadySent()) {
            $this->info('Това съобщение вече е изпращано — пропускаме. (--force за повторно)');

            return self::SUCCESS;
        }

        $players = $audience->players();

        if (blank(config('mail.reply_to.address'))) {
            $this->warn('MAIL_REPLY_TO_ADDRESS не е зададен — отговорите на писмото ще се загубят.');
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Биха получили писмо: {$players->count()} играчи.");
            foreach ($players as $player) {
                $this->line("  - {$player->name} <{$player->email}>");
            }

            return self::SUCCESS;
        }

        if ($players->isEmpty()) {
            $this->warn('Няма нито един пробвал играта — няма на кого да пращам.');

            return self::SUCCESS;
        }

        NewsletterSend::create([
            'mail_type' => self::MAIL_TYPE,
            'sent_at' => now(),
        ]);

        foreach ($players as $player) {
            $this->sendMail($player, new GameUpdateMail(
                name: $player->name,
                userUnsubscribeUrl: URL::signedRoute('newsletter.user-unsubscribe', ['user' => $player->id]),
            ));
        }

        $this->info("Писмото е изпратено до {$players->count()} играчи.");

        $this->reportMailOutcome();

        return self::SUCCESS;
    }

    private function alreadySent(): bool
    {
        return NewsletterSend::query()
            ->where('mail_type', self::MAIL_TYPE)
            ->exists();
    }
}
