<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealStatus;
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
        'number', 'title', 'client_name', 'client_phone', 'client_address',
        'total_price', 'cost_price', 'status_id', 'pipeline_type', 'current_stage_id',
        'qr_code_hash', 'parent_deal_id', 'manager_id', 'due_date',
        'stage_entered_at', 'production_started_at', 'production_finished_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status_id' => DealStatus::class,
            'pipeline_type' => PipelineType::class,
            'total_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'due_date' => 'date',
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

    /** @param Builder<Deal> $query */
    public function scopeFactory(Builder $query): void
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
