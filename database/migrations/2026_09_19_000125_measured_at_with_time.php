<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Замер — это выезд к клиенту в назначенное время, а не «когда-нибудь в этот
 * день»: замерщику нужен час, чтобы спланировать день. Дата становится датой
 * со временем; старые записи получают 00:00 и продолжают работать.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dateTime('measured_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->date('measured_at')->nullable()->change();
        });
    }
};
