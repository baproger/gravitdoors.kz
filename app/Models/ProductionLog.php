<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductionStatus;
use Database\Factories\ProductionLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ProductionStatus $status
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
