<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платежи по сделке с чеками.
 *
 * Раньше предоплата была одним числом: ни даты, ни способа, ни подтверждения.
 * Клиент платит частями, и на каждую часть нужен свой чек, поэтому теперь это
 * отдельные строки, а deals.prepayment хранит их сумму.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);
            $table->date('paid_at');
            $table->string('receipt_path');
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_payments');
    }
};
