<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->nullable()->unique();
            $table->string('name');
            $table->string('unit', 20)->default('pcs');
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('min_limit', 14, 3)->default(0)->comment('Порог для сигнала «пора закупать»');
            $table->decimal('price_per_unit', 14, 2)->default(0);
            $table->string('supplier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_stocks');
    }
};
