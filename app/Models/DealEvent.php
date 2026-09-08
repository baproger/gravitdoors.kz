<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DealEventType;
use App\Enums\Department;
use App\Enums\UserRole;
use Database\Factories\DealEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись истории сделки.
 *
 * @property DealEventType $type
 * @property Department $department
 */
class DealEvent extends Model
{
    /** @use HasFactory<DealEventFactory> */
    use HasFactory;

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
            'department' => ($department ?? Department::forRole($user?->role instanceof UserRole ? $user->role : null))->value,
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
