<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал заходов сделки на этапы.
 *
 * Раньше хранилось только время последнего входа (deals.stage_entered_at):
 * вернул сделку на этап — и 34 часа, что она там уже простояла, пропадали.
 * Теперь каждый заход — строка, а время на этапе складывается из всех заходов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_stage_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('factory_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'stage_id']);
            $table->index(['deal_id', 'left_at']);
        });

        // Открытые заходы для уже существующих сделок — с их текущего времени входа.
        $now = now();

        foreach (DB::table('deals')->whereNotNull('current_stage_id')->get(['id', 'current_stage_id', 'stage_entered_at', 'created_at']) as $deal) {
            DB::table('deal_stage_visits')->insert([
                'deal_id' => $deal->id,
                'stage_id' => $deal->current_stage_id,
                'entered_at' => $deal->stage_entered_at ?? $deal->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_visits');
    }
};
