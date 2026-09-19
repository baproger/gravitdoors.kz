<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автобонус менеджеру с закрытой сделки: источник бонуса (руками или процент
 * от сделки) и персональная ставка сотрудника поверх общей из настроек.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonuses', function (Blueprint $table): void {
            $table->string('source', 20)->default('manual')->after('status')->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('bonus_percent', 5, 2)->nullable()->after('salary');
        });
    }

    public function down(): void
    {
        // Сначала индекс, потом колонка: SQLite не удаляет колонку, на которую
        // ещё смотрит индекс, и откат падал.
        Schema::table('bonuses', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('bonus_percent'));
    }
};
