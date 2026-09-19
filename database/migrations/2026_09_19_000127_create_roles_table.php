<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роли переезжают из перечня в коде в справочник.
 *
 * Семь ролей были `UserRole` — enum, и завести «Кладовщика» без правки кода
 * было нельзя. Теперь роль — строка в таблице: название, цвет, описание и две
 * галочки поведения (ездит на замеры, работает в цеху). `users.role` и
 * `role_permissions.role` хранят код роли и уже были `varchar` — данные
 * трогать не нужно, меняется только то, откуда система берёт список.
 *
 * Семь исходных ролей помечены `is_system`: их можно переименовать и перекрасить,
 * но не удалить — на них завязаны автоматизации и заведённые сотрудники.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 60);
            $table->string('hint', 255)->nullable();
            $table->string('color', 32)->default('gray');
            // Поведение вместо `match` по enum: кому слать замеры и кому идёт
            // сдельная оплата за этап цеха.
            $table->boolean('does_surveys')->default(false);
            $table->boolean('is_factory_staff')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        $sort = 0;

        DB::table('roles')->insert(array_map(fn (UserRole $role): array => [
            'code' => $role->value,
            'name' => $role->getLabel(),
            'hint' => $role->hint(),
            'color' => $role->getColor(),
            'does_surveys' => $role->doesSurveys(),
            'is_factory_staff' => $role->isFactoryStaff(),
            'is_system' => true,
            'is_active' => true,
            'sort' => $sort += 10,
            'created_at' => $now,
            'updated_at' => $now,
        ], UserRole::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
