<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Задолженности компании: кому и сколько мы должны (поставщики, аренда, налоги, займы).
 *
 * Платёж по долгу — это расход: он создаёт запись в `expenses` со статусом
 * «оплачено», а та — списание со счёта. Один источник правды, без второго журнала.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table): void {
            $table->id();
            $table->string('counterparty');
            $table->string('category', 30)->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('due_at')->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('document_path')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained('debts')->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->string('method', 20);
            $table->foreignId('account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['debt_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
        Schema::dropIfExists('debts');
    }
};
