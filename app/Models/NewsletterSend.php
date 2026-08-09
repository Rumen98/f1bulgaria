<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал на изпратените бюлетинни имейли — източник на истината „пращано ли
 * е вече“. Дайджестът маркира състезанието (race_id), пулсът — само момента.
 */
class NewsletterSend extends Model
{
    public const TYPE_DIGEST = 'digest';

    public const TYPE_PULSE = 'pulse';

    protected $fillable = ['mail_type', 'race_id', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Race, $this> */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }
}
