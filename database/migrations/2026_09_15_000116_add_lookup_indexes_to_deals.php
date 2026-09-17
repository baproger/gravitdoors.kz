<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под самые частые выборки: наряд по сделке (parent_deal_id) в каждой
 * строке списка и карточке канбана, сделки этапа (current_stage_id) в
 * конструкторе воронок, «открытые сделки воронки» и просрочка по сроку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->index('parent_deal_id');
            $table->index('current_stage_id');
            $table->index(['pipeline_type', 'status_id']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex(['parent_deal_id']);
            $table->dropIndex(['current_stage_id']);
            $table->dropIndex(['pipeline_type', 'status_id']);
            $table->dropIndex(['due_date']);
        });
    }
};
