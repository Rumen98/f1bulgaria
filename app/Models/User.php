<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'favorite_driver_id',
        'favorite_constructor_id',
        'bio',
        'avatar_path',
        'is_admin',
        'banned_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin && $this->banned_at === null;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /** @return BelongsTo<Driver, $this> */
    public function favoriteDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'favorite_driver_id');
    }

    /** @return BelongsTo<Constructor, $this> */
    public function favoriteConstructor(): BelongsTo
    {
        return $this->belongsTo(Constructor::class, 'favorite_constructor_id');
    }

    /** @return HasMany<Prediction, $this> */
    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class);
    }

    /** @return BelongsToMany<Badge, $this> */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class)
            ->withPivot('awarded_at')
            ->withTimestamps();
    }
}
