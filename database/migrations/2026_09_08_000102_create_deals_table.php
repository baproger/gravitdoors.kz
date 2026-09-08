<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одна таблица на обе воронки. Производственный наряд — это та же сделка
 * с pipeline_type = factory и ссылкой parent_deal_id на сделку продаж.
 * Так автоматизация «продажи ⇄ завод» остаётся одним JOIN, а не синхронизацией
 * двух разных сущностей с расхождением данных.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->string('title');
            $table->string('client_name');
            $table->string('client_phone', 32)->nullable();
            $table->string('client_address')->nullable();
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('cost_price', 14, 2)->default(0);
            $table->unsignedTinyInteger('status_id')->default(10)->index();
            $table->string('pipeline_type', 20)->default('sales')->index();
            $table->foreignId('current_stage_id')->nullable()
                ->constrained('factory_stages')->nullOnDelete();
            $table->string('qr_code_hash', 64)->unique();
            $table->foreignId('parent_deal_id')->nullable()
                ->constrained('deals')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('production_started_at')->nullable();
            $table->timestamp('production_finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['pipeline_type', 'current_stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
