<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прайс-лист конфигуратора: габариты × металл + панели МДФ + фурнитура.
 * Каждая строка может быть привязана к позиции склада (material_stock_id) —
 * тогда запуск наряда в производство списывает материал автоматически.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_options', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40)->index();
            $table->string('code', 64);
            $table->string('label');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('price_type', 20)->default('fixed');
            $table->foreignId('material_stock_id')->nullable()
                ->constrained('material_stocks')->nullOnDelete();
            $table->decimal('consumption', 12, 3)->default(0)
                ->comment('Расход материала на единицу базы расчёта (м², м.п. или изделие)');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('door_options');
    }
};
