<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use App\Enums\TenderPlatform;
use App\Enums\TenderStatus;
use App\Services\AccessControl;
use Database\Factories\TenderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Тендер — закупка заказчика, на которую B2B подаёт заявку.
 *
 * Заказчик живёт в полях тендера, как клиент — в полях сделки: отдельного
 * справочника юрлиц нет. Когда лот выигран, реквизиты переезжают в сделку.
 *
 * @property int $id
 * @property string|null $announcement_number
 * @property string $title
 * @property TenderPlatform $platform
 * @property string $customer_name
 * @property string|null $customer_bin
 * @property string|null $contact_name
 * @property string|null $contact_phone
 * @property string|null $contact_email
 * @property string|null $city
 * @property string|null $delivery_address
 * @property Carbon|null $deadline_at
 * @property Carbon|null $delivery_due_date
 * @property numeric|null $security_amount
 * @property Carbon|null $security_returned_at
 * @property TenderStatus $status
 * @property int|null $manager_id
 * @property list<array{type?: string, file?: string, comment?: string}>|null $documents
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $manager
 * @property-read Collection<int, TenderLot> $lots
 */
class Tender extends Model
{
    /** @use HasFactory<TenderFactory> */
    use HasFactory;

    /** За сколько дней до окончания приёма заявок тендер подсвечивается и попадает в напоминание. */
    public const DEADLINE_WARNING_DAYS = 3;

    protected $fillable = [
        'announcement_number', 'title', 'platform',
        'customer_name', 'customer_bin', 'contact_name', 'contact_phone', 'contact_email',
        'city', 'delivery_address', 'deadline_at', 'delivery_due_date',
        'security_amount', 'security_returned_at',
        'status', 'manager_id', 'documents', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'platform' => TenderPlatform::class,
            'status' => TenderStatus::class,
            'deadline_at' => 'datetime',
            'delivery_due_date' => 'date',
            'security_amount' => 'decimal:2',
            'security_returned_at' => 'date',
            'documents' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return HasMany<TenderLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(TenderLot::class)->orderBy('id');
    }

    /** На уровне «только свои» видны свои тендеры и ничьи — как у сделок. */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        if (! $user || ! AccessControl::allows($user, Permission::WorkTenders)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (AccessControl::ownOnly(Permission::WorkTenders, $user)) {
            $query->where(fn (Builder $inner) => $inner->where('manager_id', $user->id)->orWhereNull('manager_id'));
        }
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (TenderStatus $s): string => $s->value, TenderStatus::active()));
    }

    /** Заявка ещё не подана, а приём закрывается в ближайшие дни (или уже закрылся). */
    public function scopeDeadlineSoon(Builder $query): void
    {
        $query->whereIn('status', [TenderStatus::New->value, TenderStatus::Preparing->value])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now()->addDays(self::DEADLINE_WARNING_DAYS)->endOfDay());
    }

    public function isDeadlineSoon(): bool
    {
        return $this->status->awaitsSubmission()
            && $this->deadline_at !== null
            && $this->deadline_at->lte(now()->addDays(self::DEADLINE_WARNING_DAYS)->endOfDay());
    }

    public function isDeadlinePassed(): bool
    {
        return $this->status->awaitsSubmission() && $this->deadline_at?->isPast() === true;
    }

    /** «через 2 дн.», «сегодня до 18:00», «прошёл» — для списка и карточки. */
    public function deadlineHint(): ?string
    {
        if (! $this->status->awaitsSubmission() || $this->deadline_at === null) {
            return null;
        }

        if ($this->deadline_at->isPast()) {
            return 'приём заявок закрыт';
        }

        if ($this->deadline_at->isToday()) {
            return 'сегодня до '.$this->deadline_at->format('H:i');
        }

        $days = (int) today()->diffInDays($this->deadline_at->copy()->startOfDay());

        return "через {$days} дн.";
    }

    /** Сумма нашей заявки по всем лотам. */
    public function bidTotal(): float
    {
        return round((float) $this->lots->sum(fn (TenderLot $lot): float => $lot->bidTotal()), 2);
    }

    /** Удалить можно, пока ни один лот не стал сделкой: иначе сделка потеряет происхождение. */
    public function canBeDeleted(): bool
    {
        return $this->lots()->whereNotNull('deal_id')->doesntExist();
    }

    public function displayName(): string
    {
        return filled($this->announcement_number)
            ? "№ {$this->announcement_number} · {$this->title}"
            : $this->title;
    }
}
