<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Матрица доступа: роль → право → уровень.
 *
 * Хранятся только отличия от рекомендованных значений (`Permission::default()`),
 * поэтому пустая таблица — это работающая система «из коробки», а сброс роли
 * к рекомендуемым — удаление её строк.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('role', 20);
            $table->string('permission', 40);
            $table->string('level', 10);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
