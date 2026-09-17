<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бонусы и зарплатная ведомость.
 *
 * Бонус начисляется сотруднику за месяц и попадает в ведомость после утверждения.
 * Ведомость за месяц — снимок: оклад, сдельно, бонусы, удержания, авансы, итог.
 * Пока черновик — пересчитывается из источников; после утверждения цифры
 * зафиксированы, выплата идёт частями через расходы и кассу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bonuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('month', 7)->index(); // Y-m
            $table->decimal('amount', 14, 2);
            $table->string('reason');
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'month']);
        });

        Schema::create('salary_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('month', 7)->index();
            $table->decimal('salary', 14, 2)->default(0);
            $table->decimal('piecework', 14, 2)->default(0);
            $table->decimal('bonuses', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('advances', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'month']);
        });

        Schema::create('salary_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sheet_id')->constrained('salary_sheets')->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->string('method', 20);
            $table->foreignId('account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_payments');
        Schema::dropIfExists('salary_sheets');
        Schema::dropIfExists('bonuses');
    }
};
