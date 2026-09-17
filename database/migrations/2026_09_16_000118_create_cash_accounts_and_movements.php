<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Касса и банк: счета и единый журнал движений денег.
 *
 * Остаток счёта не хранится — он всегда «начальный остаток + приход − расход»
 * по журналу: так его нельзя рассинхронизировать правкой поля. Источник каждого
 * движения (платёж по сделке, расход, перевод, корректировка) записан, чтобы
 * удаление источника снимало и движение.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 10); // cash | bank
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('cash_accounts')->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->decimal('amount', 14, 2);
            $table->date('happened_at');
            $table->nullableMorphs('source');
            $table->uuid('transfer_id')->nullable()->index();
            $table->string('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'happened_at']);
        });

        Schema::table('deal_payments', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('method')->constrained('cash_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deal_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_accounts');
    }
};
