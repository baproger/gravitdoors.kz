<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Восьмая базовая роль — «Менеджер B2B».
 *
 * Юрлица и тендеры ведёт отдельный человек: у него свои тендеры с лотами и
 * документами заявки, а сделки — в общей воронке продаж, как у менеджера.
 * Роль системная: на её код завязаны права по умолчанию (`Permission::defaults`).
 * Если директор уже завёл свою роль с кодом `b2b`, её не трогаем.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('code', UserRole::B2b->value)->exists()) {
            return;
        }

        $role = UserRole::B2b;

        DB::table('roles')->insert([
            'code' => $role->value,
            'name' => $role->getLabel(),
            'hint' => $role->hint(),
            'color' => $role->getColor(),
            'does_surveys' => $role->doesSurveys(),
            'is_factory_staff' => $role->isFactoryStaff(),
            'is_system' => true,
            'is_active' => true,
            // Сразу за менеджером продаж — в списках роли идут по отделам.
            'sort' => 15,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('code', UserRole::B2b->value)->where('is_system', true)->delete();
    }
};
