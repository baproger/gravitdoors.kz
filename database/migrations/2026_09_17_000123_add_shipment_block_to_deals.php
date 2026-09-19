<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Блокировка отгрузки: финансы придерживают заказ, пока клиент не рассчитался.
 *
 * Флаг у сделки, а не у наряда: цех доделывает дверь в любом случае, стоп
 * стоит на последнем шаге — отгрузке и закрытии.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->timestamp('shipment_blocked_at')->nullable()->after('notes');
            $table->string('shipment_block_reason')->nullable()->after('shipment_blocked_at');
            $table->foreignId('shipment_blocked_by')->nullable()->after('shipment_block_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipment_blocked_by');
            $table->dropColumn(['shipment_blocked_at', 'shipment_block_reason']);
        });
    }
};
