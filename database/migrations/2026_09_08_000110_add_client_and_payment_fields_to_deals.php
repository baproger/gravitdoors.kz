<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Полная карточка клиента и условия сделки.
 *
 * До этого в сделке были только имя, телефон и адрес — менеджеру негде было
 * держать БИН для договора, второй телефон, канал обращения и условия оплаты,
 * и всё это уезжало в заметку свободным текстом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            // Клиент
            $table->string('client_type', 20)->default('individual')->after('client_name');
            $table->string('client_company')->nullable()->after('client_type');
            $table->string('client_bin', 20)->nullable()->after('client_company');
            $table->string('client_email')->nullable()->after('client_phone');
            $table->string('client_phone_extra', 32)->nullable()->after('client_email');
            $table->string('city', 100)->nullable()->after('client_address');
            $table->string('source', 32)->nullable()->after('city');

            // Договор
            $table->string('contract_number', 64)->nullable()->after('source');
            $table->date('contract_date')->nullable()->after('contract_number');
            $table->date('measured_at')->nullable()->after('contract_date');

            // Деньги и услуги
            $table->decimal('prepayment', 14, 2)->default(0)->after('cost_price');
            $table->string('payment_method', 20)->nullable()->after('prepayment');
            $table->decimal('delivery_cost', 12, 2)->default(0)->after('payment_method');
            $table->decimal('installation_cost', 12, 2)->default(0)->after('delivery_cost');

            $table->index('client_phone');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['client_phone']);
            $table->dropColumn([
                'client_type', 'client_company', 'client_bin', 'client_email', 'client_phone_extra',
                'city', 'source', 'contract_number', 'contract_date', 'measured_at',
                'prepayment', 'payment_method', 'delivery_cost', 'installation_cost',
            ]);
        });
    }
};
