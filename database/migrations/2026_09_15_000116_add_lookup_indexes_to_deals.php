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
        // MySQL: когда появляется индекс, пригодный для внешнего ключа, он молча
        // удаляет свой служебный индекс ключа и переводит ключ на новый. Поэтому
        // удалить наш индекс можно только сняв ключ, а после — вернув его.
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['parent_deal_id']);
            $table->dropForeign(['current_stage_id']);
            $table->dropIndex(['parent_deal_id']);
            $table->dropIndex(['current_stage_id']);
            $table->dropIndex(['pipeline_type', 'status_id']);
            $table->dropIndex(['due_date']);
            $table->foreign('parent_deal_id')->references('id')->on('deals')->cascadeOnDelete();
            $table->foreign('current_stage_id')->references('id')->on('factory_stages')->nullOnDelete();
        });
    }
};
