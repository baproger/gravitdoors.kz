<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Сделка отдела продаж или производственный наряд завода — различаются
 * значением pipeline_type. Наряд знает свою сделку через parent_deal_id,
 * сделка свой наряд — через productionOrder().
 *
 * @property DealStatus $status_id
 * @property PipelineType $pipeline_type
 */
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'number', 'title',
        'client_name', 'client_type', 'client_company', 'client_bin',
        'client_phone', 'client_email', 'client_phone_extra', 'client_address', 'city', 'source',
        'contract_number', 'contract_date', 'measured_at',
        'total_price', 'cost_price', 'prepayment', 'payment_method',
        'delivery_cost', 'installation_cost',
        'status_id', 'pipeline_type', 'current_stage_id',
        'qr_code_hash', 'parent_deal_id', 'manager_id', 'due_date',
        'stage_entered_at', 'production_started_at', 'production_finished_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status_id' => DealStatus::class,
            'pipeline_type' => PipelineType::class,
            'client_type' => ClientType::class,
            'source' => DealSource::class,
            'payment_method' => PaymentMethod::class,
            'total_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'prepayment' => 'decimal:2',
            'delivery_cost' => 'decimal:2',
            'installation_cost' => 'decimal:2',
            'due_date' => 'date',
            'contract_date' => 'date',
            'measured_at' => 'date',
            'stage_entered_at' => 'datetime',
            'production_started_at' => 'datetime',
            'production_finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deal $deal): void {
            $deal->qr_code_hash ??= static::generateHash();
            $deal->number ??= static::generateNumber($deal->pipeline_type ?? PipelineType::Sales);
            $deal->stage_entered_at ??= now();
        });
    }

    public static function generateHash(): string
    {
        do {
            $hash = Str::lower(Str::random(24));
        } while (static::withTrashed()->where('qr_code_hash', $hash)->exists());

        return $hash;
    }

    /**
     * Сквозная нумерация внутри воронки и года: GRV-26-0001, PRD-26-0001.
     * Считаем по количеству, а не по max(id), иначе номера перескакивали бы
     * через записи соседней воронки, и бухгалтерия видела бы дыры в реестре.
     */
    public static function generateNumber(PipelineType|string $type): string
    {
        $type = $type instanceof PipelineType ? $type : PipelineType::from($type);
        $prefix = $type === PipelineType::Factory ? 'PRD' : 'GRV';
        $year = now()->format('y');

        $sequence = static::withTrashed()
            ->where('pipeline_type', $type->value)
            ->where('number', 'like', "{$prefix}-{$year}-%")
            ->count();

        do {
            $sequence++;
            $number = sprintf('%s-%s-%04d', $prefix, $year, $sequence);
        } while (static::withTrashed()->where('number', $number)->exists());

        return $number;
    }

    /** @return BelongsTo<FactoryStage, $this> */
    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(FactoryStage::class, 'current_stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Сделка продаж, породившая этот наряд. @return BelongsTo<Deal, $this> */
    public function parentDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'parent_deal_id');
    }

    /** Наряд завода, созданный из этой сделки. @return HasOne<Deal, $this> */
    public function productionOrder(): HasOne
    {
        return $this->hasOne(Deal::class, 'parent_deal_id')
            ->where('pipeline_type', PipelineType::Factory->value);
    }

    /**
     * Позиции сделки. В один заказ может входить несколько разных дверей,
     * поэтому связь множественная: суммы и списание материалов считаются
     * по всем позициям сразу.
     *
     * @return HasMany<DoorConfiguration, $this>
     */
    public function doorConfigurations(): HasMany
    {
        return $this->hasMany(DoorConfiguration::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProductionLog, $this> */
    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @param Builder<Deal> $query */
    public function scopeSales(Builder $query): void
    {
        $query->where('pipeline_type', PipelineType::Sales->value);
    }

    /**
     * Только производственные наряды.
     *
     * Назван не `factory`: `Deal::factory()` уже занято Eloquent-фабрикой,
     * и одинаковое имя означало бы разное в статическом вызове и на билдере.
     *
     * @param  Builder<Deal>  $query
     */
    public function scopeFactoryOrders(Builder $query): void
    {
        $query->where('pipeline_type', PipelineType::Factory->value);
    }

    /** @param Builder<Deal> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status_id', [DealStatus::Completed->value, DealStatus::Cancelled->value]);
    }

    public function isFactoryOrder(): bool
    {
        return $this->pipeline_type === PipelineType::Factory;
    }

    /** Сделка продаж, к которой относится запись: для наряда — родитель, для сделки — она сама. */
    public function salesDeal(): self
    {
        return $this->isFactoryOrder() ? ($this->parentDeal ?? $this) : $this;
    }

    /** Позиции сделки продаж — у наряда своих позиций нет, он берёт родительские. */
    public function configurations(): Collection
    {
        return $this->salesDeal()->doorConfigurations;
    }

    /** Сколько дверей всего в заказе, с учётом количества в каждой позиции. */
    public function doorsCount(): int
    {
        return (int) $this->configurations()->sum('quantity');
    }

    /** Сколько клиент ещё должен. */
    public function remainingPayment(): float
    {
        return max(0.0, round((float) $this->total_price - (float) $this->prepayment, 2));
    }

    public function isPaidInFull(): bool
    {
        return $this->remainingPayment() <= 0.0 && (float) $this->total_price > 0.0;
    }

    /** Услуги сверх стоимости самих дверей. */
    public function servicesCost(): float
    {
        return round((float) $this->delivery_cost + (float) $this->installation_cost, 2);
    }

    /** Как клиента показывать в списках: компанию — по названию, физлицо — по имени. */
    public function clientTitle(): string
    {
        return $this->client_type === ClientType::Company && filled($this->client_company)
            ? $this->client_company
            : $this->client_name;
    }

    public function getProfitAttribute(): float
    {
        return (float) $this->total_price - (float) $this->cost_price;
    }

    public function getMarginAttribute(): float
    {
        $total = (float) $this->total_price;

        return $total > 0.0 ? round($this->profit / $total * 100, 2) : 0.0;
    }

    /** Сколько часов сделка стоит на текущем этапе — для «⏱» на канбане. */
    public function getHoursOnStageAttribute(): float
    {
        return $this->stage_entered_at ? round($this->stage_entered_at->diffInMinutes(now()) / 60, 1) : 0.0;
    }

    public function getTrackUrlAttribute(): string
    {
        return route('track.show', $this->qr_code_hash);
    }
}
