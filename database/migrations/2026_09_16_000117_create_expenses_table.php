<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расходы компании вне сделок: аренда, налоги, транспорт, реклама, прочее.
 *
 * Запись вносит менеджер или администратор, подтверждает администратор:
 * до подтверждения расход не попадает в финансовую сводку. Чек обязателен
 * для всего, кроме зарплаты и налогов — там подтверждение приходит из банка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 30)->index();
            $table->decimal('amount', 14, 2);
            $table->date('spent_at')->index();
            $table->string('method', 20);
            $table->foreignId('account_id')->nullable(); // счёт кассы/банка — шаг 3 плана
            $table->string('counterparty')->nullable();
            $table->text('comment')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
