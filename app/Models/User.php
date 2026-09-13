<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property UserRole $role
 * @property string|null $phone
 * @property bool $is_active
 * @property string|null $avatar_path
 * @property numeric $salary
 * @property Carbon|null $hired_at
 * @property Carbon|null $birth_date
 * @property-read Collection<int, Deal> $deals
 * @property-read int|null $deals_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, ProductionLog> $productionLogs
 * @property-read int|null $production_logs_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSalary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone', 'is_active',
        'avatar_path', 'salary', 'hired_at', 'birth_date',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'salary' => 'decimal:2',
            'hired_at' => 'date',
            'birth_date' => 'date',
        ];
    }

    /** Аватар в шапке панели и в карточке сотрудника. */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_path
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /** Инициалы для заглушки аватара. */
    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** Сколько сотрудник в компании: «7 мес.», «1 г. 3 мес.». */
    public function tenure(): ?string
    {
        if (! $this->hired_at) {
            return null;
        }

        $months = (int) $this->hired_at->diffInMonths(now());

        if ($months < 12) {
            return max(1, $months).' мес.';
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;

        return $years.' г.'.($rest > 0 ? " {$rest} мес." : '');
    }

    /** Заблокированный сотрудник в панель не попадает даже с валидным паролем. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'manager_id');
    }

    /** @return HasMany<ProductionLog, $this> */
    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class, 'worker_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isFactoryStaff(): bool
    {
        return in_array($this->role, [UserRole::Master, UserRole::Worker], true);
    }

    /** Сдельный заработок за период по закрытым этапам цеха. */
    public function payoutBetween(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) $this->productionLogs()
            ->whereBetween('finished_at', [$from, $to])
            ->sum('payout');
    }
}
