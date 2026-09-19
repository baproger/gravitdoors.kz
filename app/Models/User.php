<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Uploads\PrivateFiles;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Role|null $role роль справочником; код лежит в колонке `role`
 * @property string|null $phone
 * @property bool $is_active
 * @property string|null $avatar_path
 * @property numeric $salary
 * @property numeric|null $bonus_percent
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
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
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone', 'is_active',
        'avatar_path', 'salary', 'bonus_percent', 'hired_at', 'birth_date',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'salary' => 'decimal:2',
            'bonus_percent' => 'decimal:2',
            'hired_at' => 'date',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'birth_date' => 'date',
        ];
    }

    /** Аватар в шапке панели и в карточке сотрудника. */
    public function getFilamentAvatarUrl(): ?string
    {
        return PrivateFiles::url($this->avatar_path, minutes: 120);
    }

    /*
     | Двухфакторный вход через приложение-аутентификатор. Секрет и коды
     | восстановления лежат зашифрованными (каст `encrypted`): утечка базы
     | без APP_KEY второй фактор не раскроет.
     */
    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** Подпись записи в приложении-аутентификаторе: почта, по ней сотрудник входит. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return array<string>|null */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param array<string>|null $codes */
    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
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

    /**
     * Роль сотрудника строкой — как она лежит в колонке.
     *
     * Читать код, а не `role->code`: проверки прав идут сотнями за отрисовку,
     * и тянуть ради них справочник незачем.
     */
    public function roleCode(): string
    {
        return (string) ($this->attributes['role'] ?? '');
    }

    /**
     * Роль справочником. Аксессор, а не связь: код лежит прямо в колонке, а
     * справочник целиком уже в памяти запроса — лишнего запроса не будет.
     *
     * Роль могла быть скрыта или переименована; если строки нет вовсе (база
     * правилась руками), отдаём null, и реестр прав закроет всё.
     */
    protected function role(): Attribute
    {
        return Attribute::get(fn (): ?Role => Role::byCode($this->roleCode()));
    }

    public function isAdmin(): bool
    {
        return $this->roleCode() === UserRole::Admin->value;
    }

    public function isFactoryStaff(): bool
    {
        return $this->role?->isFactoryStaff() ?? false;
    }

    /** Сдельный заработок за период по закрытым этапам цеха. */
    public function payoutBetween(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        return (float) $this->productionLogs()
            ->whereBetween('finished_at', [$from, $to])
            ->sum('payout');
    }
}
