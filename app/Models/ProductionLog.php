<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionStatus;
use Database\Factories\ProductionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $deal_id
 * @property int $stage_id
 * @property int|null $worker_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property ProductionStatus $status
 * @property numeric $payout
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Deal|null $deal
 * @property-read FactoryStage $stage
 * @property-read User|null $worker
 *
 * @method static \Database\Factories\ProductionLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereDealId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog wherePayout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereStageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductionLog whereWorkerId($value)
 *
 * @mixin \Eloquent
 */
class ProductionLog extends Model
{
    /** @use HasFactory<ProductionLogFactory> */
    use HasFactory;

    protected $fillable = [
        'deal_id', 'stage_id', 'worker_id', 'started_at', 'finished_at', 'status', 'payout', 'comment',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductionStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payout' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<FactoryStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(FactoryStage::class, 'stage_id');
    }

    /** @return BelongsTo<User, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /** Фактическая длительность этапа в часах; null — пока этап не закрыт. */
    public function durationHours(): ?float
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return round($this->started_at->diffInMinutes($this->finished_at) / 60, 2);
    }

    /** Отставание от норматива этапа в часах: > 0 — дольше плана. */
    public function deviationHours(): ?float
    {
        $actual = $this->durationHours();

        return $actual === null ? null : round($actual - (float) $this->stage->estimated_hours, 2);
    }
}
