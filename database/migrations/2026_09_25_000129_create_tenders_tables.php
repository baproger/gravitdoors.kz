<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тендеры и лоты.
 *
 * Тендер — закупка заказчика (госзакуп, Самрук, коммерческая площадка): кто
 * покупает, до какого часа принимаются заявки, документы заявки. Лот — то, на
 * что подаётся цена: изделие, размер, количество, цена заказчика и наша.
 * Выигранный лот превращается в сделку продаж (`deal_id`) — одна сделка на лот,
 * потому что по лоту заключается свой договор и идёт своя поставка.
 *
 * Сделке добавлена `contract_price`: цена, которую зафиксировал тендер. Без неё
 * сумма сделки пересчитывалась бы по прайсу и расходилась бы с договором.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table): void {
            $table->id();
            $table->string('announcement_number', 64)->nullable()->index();
            $table->string('title');
            $table->string('platform', 20);
            $table->string('customer_name');
            $table->string('customer_bin', 12)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('city', 100)->nullable();
            $table->text('delivery_address')->nullable();
            // Окончание приёма заявок — с часом: площадка закрывает приём минута в минуту.
            $table->dateTime('deadline_at')->nullable()->index();
            $table->date('delivery_due_date')->nullable();
            // Обеспечение заявки: деньги или гарантия, которые вернутся после итогов.
            $table->decimal('security_amount', 14, 2)->nullable();
            $table->date('security_returned_at')->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('documents')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tender_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->string('lot_number', 64)->nullable();
            $table->string('name');
            $table->string('category', 32);
            $table->string('model', 32);
            $table->unsignedInteger('height');
            $table->unsignedInteger('width');
            $table->unsignedInteger('quantity');
            // Цены за единицу: заказчика (потолок) и наша.
            $table->decimal('budget_unit_price', 14, 2)->nullable();
            $table->decimal('bid_unit_price', 14, 2)->nullable();
            // Расчёт по прайсу при сохранении — чтобы видеть маржу до подачи цены.
            $table->decimal('list_unit_price', 14, 2)->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->string('result', 20)->default('pending')->index();
            // Кто выиграл и почём, если не мы, — разбор проигранных тендеров.
            $table->string('winner_name')->nullable();
            $table->decimal('winner_unit_price', 14, 2)->nullable();
            $table->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->decimal('contract_price', 14, 2)->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('contract_price');
        });

        Schema::dropIfExists('tender_lots');
        Schema::dropIfExists('tenders');
    }
};
