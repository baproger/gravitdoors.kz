<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этапы обеих воронок в одной таблице: колонка pipeline_type разделяет
 * «Отдел продаж» и «Завод». Таблица названа так, как в ТЗ (factory_stages),
 * но обслуживает обе воронки — иначе пришлось бы дублировать логику сортировки,
 * переходов и настроек в двух одинаковых таблицах.
 *
 * Флаги triggers_production / completes_production — это и есть точки
 * автоматизации: они настраиваются в админке, а не зашиты в код.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factory_stages', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline_type', 20)->index();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->decimal('estimated_hours', 6, 2)->default(0);
            $table->decimal('operation_cost', 12, 2)->default(0)->comment('Сдельная оплата за операцию');
            $table->string('color', 32)->default('gray');
            $table->string('icon', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_final')->default(false);
            $table->boolean('triggers_production')->default(false)
                ->comment('Вход на этап создаёт наряд в воронке завода');
            $table->boolean('completes_production')->default(false)
                ->comment('Завершение этапа возвращает сделку продаж в «Готово к отгрузке»');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pipeline_type', 'code']);
            $table->index(['pipeline_type', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_stages');
    }
};
