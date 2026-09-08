<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Движения склада: приход и списание под наряд. Остаток material_stocks.quantity — производная от этих строк. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_stock_id')->constrained('material_stocks')->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12)->default('out');
            $table->decimal('quantity', 14, 3);
            $table->decimal('price_per_unit', 14, 2)->default(0);
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->index(['material_stock_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
