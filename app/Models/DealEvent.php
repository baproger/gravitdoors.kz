<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealEventType;
use App\Enums\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Запись истории сделки.
 *
 * @property int $id
 * @property int $deal_id
 * @property int|null $user_id
 * @property Department $department
 * @property DealEventType $type
 * @property string $description
 * @property array<array-key, mixed>|null $changes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Deal|null $deal
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereDealId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereDepartment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DealEvent whereUserId($value)
 *
 * @mixin \Eloquent
 */
class DealEvent extends Model
{
    protected $fillable = ['deal_id', 'user_id', 'department', 'type', 'description', 'changes'];

    protected function casts(): array
    {
        return [
            'type' => DealEventType::class,
            'department' => Department::class,
            'changes' => 'array',
        ];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Записать событие.
     *
     * Отдел определяется по роли автора в момент действия и сохраняется в строке:
     * если сотрудник потом сменит должность, история не должна переписываться.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function record(
        Deal $deal,
        DealEventType $type,
        string $description,
        ?User $user = null,
        ?array $changes = null,
        ?Department $department = null,
    ): self {
        $user ??= auth()->user();

        return static::create([
            'deal_id' => $deal->id,
            'user_id' => $user?->id,
            'department' => ($department ?? Department::forRole($user?->roleCode()))->value,
            'type' => $type,
            'description' => $description,
            'changes' => $changes,
        ]);
    }

    public function actorName(): string
    {
        return $this->user?->name ?? 'Система';
    }
}
