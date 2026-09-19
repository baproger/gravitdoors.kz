<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Результат замера: что замерщик привёз с выезда.
 *
 * `measured_at` — это план («поедешь в четверг в 14:00»), его ставит менеджер.
 * Факта замера в системе не было вовсе: размеры и особенности проёма
 * передавались голосом. Теперь замерщик записывает их сам, а сделка хранит
 * и цифры, и того, кто их снял.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            // Миллиметры, как и габариты двери в door_configurations.
            $table->unsignedInteger('measurement_height')->nullable()->after('measured_at');
            $table->unsignedInteger('measurement_width')->nullable()->after('measurement_height');
            $table->text('measurement_comment')->nullable()->after('measurement_width');
            $table->dateTime('measurement_done_at')->nullable()->after('measurement_comment');
            $table->foreignId('measurement_by_id')->nullable()->after('measurement_done_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('measurement_by_id');
            $table->dropColumn([
                'measurement_height', 'measurement_width',
                'measurement_comment', 'measurement_done_at',
            ]);
        });
    }
};
