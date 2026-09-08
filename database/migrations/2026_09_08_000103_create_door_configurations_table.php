<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Спецификация двери. Одна строка — одна позиция сделки: в один заказ может
 * входить несколько разных дверей (входная в квартиру + тамбурная), поэтому
 * связь с deals — hasMany, а не hasOne.
 *
 * Колонки выбора хранят `code` из door_options, а не текст:
 * прайс можно править в админке, не трогая уже посчитанные заказы.
 * price_breakdown фиксирует расшифровку цены на момент расчёта — чтобы
 * изменение прайса задним числом не переписывало историю сделок.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1)->comment('Порядковый номер позиции в сделке');
            $table->string('label')->nullable()->comment('Назначение двери: входная, тамбурная, техническая');
            $table->unsignedInteger('height')->default(2050)->comment('мм');
            $table->unsignedInteger('width')->default(950)->comment('мм');
            $table->string('opening_side', 16)->default('right');
            $table->string('metal_thickness', 64)->nullable();
            $table->string('outer_mdf_panel', 64)->nullable();
            $table->string('inner_mdf_panel', 64)->nullable();
            $table->string('lock_system', 64)->nullable();
            $table->string('insulation_type', 64)->nullable();
            $table->string('color_coating', 64)->nullable();
            $table->json('additional_options')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('calculated_price', 14, 2)->default(0);
            $table->json('price_breakdown')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_configurations');
    }
};
