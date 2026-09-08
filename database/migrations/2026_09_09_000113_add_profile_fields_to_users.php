<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Карточка сотрудника: аватар, оклад и даты. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('phone');
            $table->decimal('salary', 12, 2)->default(0)->after('avatar_path');
            $table->date('hired_at')->nullable()->after('salary');
            $table->date('birth_date')->nullable()->after('hired_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'salary', 'hired_at', 'birth_date']);
        });
    }
};
