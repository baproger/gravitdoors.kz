<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лог прохождения наряда по этапам цеха. Он же — основание для сдельной оплаты:
 * payout фиксируется из factory_stages.operation_cost в момент завершения этапа,
 * чтобы поднятие расценок не пересчитывало уже закрытые смены.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('factory_stages')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('payout', 12, 2)->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_logs');
    }
};
