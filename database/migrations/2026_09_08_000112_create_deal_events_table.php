<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История сделки: кто, когда и что сделал.
 *
 * Отдел пишется в момент события, а не берётся из роли пользователя при показе:
 * сотрудник может сменить должность, а история должна остаться такой, какой была.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department', 20)->index();
            $table->string('type', 40)->index();
            $table->string('description');
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_events');
    }
};
