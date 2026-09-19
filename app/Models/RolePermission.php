<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно отличие матрицы доступа от рекомендованного значения.
 *
 * @property int $id
 * @property string $role код роли из справочника `roles`
 * @property Permission $permission
 * @property AccessLevel $level
 * @property int|null $updated_by
 *
 * @method static Builder<static>|RolePermission query()
 */
class RolePermission extends Model
{
    protected $fillable = ['role', 'permission', 'level', 'updated_by'];

    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
            'level' => AccessLevel::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
