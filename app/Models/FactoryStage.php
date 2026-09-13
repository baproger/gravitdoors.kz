<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use Database\Factories\FactoryStageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Этап воронки. Обслуживает и продажи, и цех — см. миграцию factory_stages.
 *
 * @property int $id
 * @property PipelineType $pipeline_type
 * @property string $code
 * @property string $name
 * @property int $order
 * @property numeric $estimated_hours
 * @property numeric $operation_cost
 * @property string $color
 * @property string|null $icon
 * @property string|null $description
 * @property bool $is_initial
 * @property bool $is_final
 * @property bool $triggers_production
 * @property bool $completes_production
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed>|null $required_fields
 * @property-read Collection<int, Deal> $deals
 * @property-read int|null $deals_count
 * @property-read Collection<int, ProductionLog> $productionLogs
 * @property-read int|null $production_logs_count
 *
 * @method static Builder<static>|FactoryStage active()
 * @method static \Database\Factories\FactoryStageFactory factory($count = null, $state = [])
 * @method static Builder<static>|FactoryStage newModelQuery()
 * @method static Builder<static>|FactoryStage newQuery()
 * @method static Builder<static>|FactoryStage ofPipeline(\App\Enums\PipelineType|string $type)
 * @method static Builder<static>|FactoryStage ordered()
 * @method static Builder<static>|FactoryStage query()
 * @method static Builder<static>|FactoryStage whereCode($value)
 * @method static Builder<static>|FactoryStage whereColor($value)
 * @method static Builder<static>|FactoryStage whereCompletesProduction($value)
 * @method static Builder<static>|FactoryStage whereCreatedAt($value)
 * @method static Builder<static>|FactoryStage whereDescription($value)
 * @method static Builder<static>|FactoryStage whereEstimatedHours($value)
 * @method static Builder<static>|FactoryStage whereIcon($value)
 * @method static Builder<static>|FactoryStage whereId($value)
 * @method static Builder<static>|FactoryStage whereIsActive($value)
 * @method static Builder<static>|FactoryStage whereIsFinal($value)
 * @method static Builder<static>|FactoryStage whereIsInitial($value)
 * @method static Builder<static>|FactoryStage whereName($value)
 * @method static Builder<static>|FactoryStage whereOperationCost($value)
 * @method static Builder<static>|FactoryStage whereOrder($value)
 * @method static Builder<static>|FactoryStage wherePipelineType($value)
 * @method static Builder<static>|FactoryStage whereRequiredFields($value)
 * @method static Builder<static>|FactoryStage whereTriggersProduction($value)
 * @method static Builder<static>|FactoryStage whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class FactoryStage extends Model
{
    /** @use HasFactory<FactoryStageFactory> */
    use HasFactory;

    protected $fillable = [
        'pipeline_type', 'code', 'name', 'order', 'estimated_hours', 'operation_cost',
        'color', 'icon', 'description', 'is_initial', 'is_final',
        'triggers_production', 'completes_production', 'required_fields', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pipeline_type' => PipelineType::class,
            'order' => 'integer',
            'estimated_hours' => 'decimal:2',
            'operation_cost' => 'decimal:2',
            'is_initial' => 'boolean',
            'is_final' => 'boolean',
            'triggers_production' => 'boolean',
            'completes_production' => 'boolean',
            'is_active' => 'boolean',
            'required_fields' => 'array',
        ];
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'current_stage_id');
    }

    /** @return HasMany<ProductionLog, $this> */
    public function productionLogs(): HasMany
    {
        return $this->hasMany(ProductionLog::class, 'stage_id');
    }

    /** @param Builder<FactoryStage> $query */
    public function scopeOfPipeline(Builder $query, PipelineType|string $type): void
    {
        $query->where('pipeline_type', $type instanceof PipelineType ? $type->value : $type);
    }

    /** @param Builder<FactoryStage> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<FactoryStage> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order')->orderBy('id');
    }

    /**
     * Требования этапа как перечисления.
     *
     * @return list<StageRequirement>
     */
    public function requirements(): array
    {
        return collect($this->required_fields ?? [])
            ->map(fn (string $value): ?StageRequirement => StageRequirement::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Чего не хватает сделке для входа на этап.
     *
     * @return list<StageRequirement>
     */
    public function missingFor(Deal $deal): array
    {
        return array_values(array_filter(
            $this->requirements(),
            fn (StageRequirement $requirement): bool => ! $requirement->isSatisfiedBy($deal),
        ));
    }

    public function next(): ?self
    {
        return static::query()
            ->ofPipeline($this->pipeline_type)
            ->active()
            ->where('order', '>', $this->order)
            ->ordered()
            ->first();
    }

    public function previous(): ?self
    {
        return static::query()
            ->ofPipeline($this->pipeline_type)
            ->active()
            ->where('order', '<', $this->order)
            ->orderByDesc('order')
            ->first();
    }

    /**
     * Этап, для входа на который нужен проведённый замер. До него дата замера
     * в прошлом означает «замерщик не съездил», после — просто история.
     */
    public static function measurementGate(): ?self
    {
        return static::query()
            ->ofPipeline(PipelineType::Sales)
            ->active()
            ->ordered()
            ->get()
            ->first(fn (self $stage): bool => in_array(StageRequirement::MeasuredAt->value, $stage->required_fields ?? [], true));
    }

    /** Первый этап воронки: явно помеченный is_initial, иначе самый верхний по порядку. */
    public static function firstOf(PipelineType $type): ?self
    {
        return static::query()->ofPipeline($type)->active()->orderByDesc('is_initial')->ordered()->first();
    }
}
