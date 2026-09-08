<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог изделий, документы сделки и правила перехода по этапам.
 *
 * `required_fields` — то, без чего сделку нельзя пустить на этап. Список
 * настраивается в админке, поэтому регламент отдела продаж меняется без кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('door_configurations', function (Blueprint $table) {
            $table->string('category', 20)->default('comfort')->after('position');
            $table->string('model', 32)->nullable()->after('category');
            $table->dropColumn('label');
        });

        Schema::table('factory_stages', function (Blueprint $table) {
            $table->json('required_fields')->nullable()->after('completes_production');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->json('documents')->nullable()->after('contract_date');
        });
    }

    public function down(): void
    {
        Schema::table('door_configurations', function (Blueprint $table) {
            $table->string('label')->nullable()->after('position');
            $table->dropColumn(['category', 'model']);
        });

        Schema::table('factory_stages', function (Blueprint $table) {
            $table->dropColumn('required_fields');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('documents');
        });
    }
};
