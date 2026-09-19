<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Один заход сделки на этап: когда вошла, когда ушла.
 *
 * @property int $deal_id
 * @property int $stage_id
 * @property int|null $user_id
 * @property Carbon $entered_at
 * @property Carbon|null $left_at
 * @property-read User|null $user
 */
class DealStageVisit extends Model
{
    protected $fillable = ['deal_id', 'stage_id', 'user_id', 'entered_at', 'left_at'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'left_at' => 'datetime'];
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

    /** Кто привёл сделку на этап; пусто у переходов без пользователя. @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Длительность захода в часах; у открытого — до текущего момента. */
    public function hours(): float
    {
        return round($this->entered_at->diffInMinutes($this->left_at ?? now()) / 60, 1);
    }
}
