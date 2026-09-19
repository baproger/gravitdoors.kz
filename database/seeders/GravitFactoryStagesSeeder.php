<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PipelineType;
use App\Models\FactoryStage;
use Illuminate\Database\Seeder;

/**
 * Настоящая технологическая цепочка цеха Gravit — 13 этапов.
 *
 * `FactoryStageSeeder` даёт короткую демонстрационную воронку из шести операций,
 * на ней держатся тесты. Здесь — боевая конфигурация владельца: она накатывается
 * последней и переименовывает/достраивает этапы, не трогая уже закрытые работы
 * (строки те же, меняются только названия и порядок), поэтому зарплата цеха
 * и история нарядов остаются на месте.
 */
class GravitFactoryStagesSeeder extends Seeder
{
    /**
     * Код → [название, порядок, цвет, иконка].
     *
     * Коды шести первых совпадают с демонстрационными: так этап не заводится
     * заново, а переименовывается, и наряды не теряют свою историю.
     */
    private const STAGES = [
        ['code' => 'design_approval', 'name' => 'Согласование дизайна', 'color' => 'gray', 'icon' => 'heroicon-o-swatch'],
        ['code' => 'drawing', 'name' => 'Создание чертежа', 'color' => 'info', 'icon' => 'heroicon-o-pencil-square'],
        ['code' => 'metal_cutting', 'name' => 'Раскрой материала', 'color' => 'gray', 'icon' => 'heroicon-o-scissors'],
        ['code' => 'laser_cutting', 'name' => 'Лазерная резка', 'color' => 'warning', 'icon' => 'heroicon-o-bolt'],
        ['code' => 'bending', 'name' => 'Гибка листа', 'color' => 'gray', 'icon' => 'heroicon-o-arrow-path-rounded-square'],
        ['code' => 'welding', 'name' => 'Сварка и сборка', 'color' => 'warning', 'icon' => 'heroicon-o-fire'],
        ['code' => 'painting', 'name' => 'Полимерная покраска', 'color' => 'info', 'icon' => 'heroicon-o-paint-brush'],
        ['code' => 'insulation_mdf', 'name' => 'МДФ и стекло', 'color' => 'primary', 'icon' => 'heroicon-o-square-3-stack-3d'],
        ['code' => 'hardware', 'name' => 'Фурнитура и замки', 'color' => 'info', 'icon' => 'heroicon-o-key'],
        ['code' => 'assembly', 'name' => 'Сборка заказа', 'color' => 'primary', 'icon' => 'heroicon-o-cube'],
        ['code' => 'qc_packing', 'name' => 'ОТК и упаковка', 'color' => 'success', 'icon' => 'heroicon-o-shield-check'],
        ['code' => 'shipping', 'name' => 'Доставка', 'color' => 'info', 'icon' => 'heroicon-o-truck'],
        ['code' => 'installation', 'name' => 'Монтаж', 'color' => 'success', 'icon' => 'heroicon-o-wrench-screwdriver'],
    ];

    /** Этап, после которого наряд закрывается и сделка уходит отделу продаж. */
    public const COMPLETES = 'qc_packing';

    public function run(): void
    {
        $order = 10;

        foreach (self::STAGES as $stage) {
            $row = FactoryStage::query()->firstOrNew([
                'pipeline_type' => PipelineType::Factory->value,
                'code' => $stage['code'],
            ]);

            // Норматив и расценку не трогаем у существующих этапов: их задаёт
            // владелец в админке, и сидер не должен затирать настроенное.
            $row->fill([
                'name' => $stage['name'],
                'order' => $order,
                'color' => $stage['color'],
                'icon' => $stage['icon'],
                'is_active' => true,
                'is_initial' => $order === 10,
                'is_final' => $stage['code'] === self::COMPLETES,
                'completes_production' => $stage['code'] === self::COMPLETES,
                'triggers_production' => false,
            ]);

            $row->estimated_hours ??= 0;
            $row->operation_cost ??= 0;
            $row->required_fields ??= [];

            $row->save();

            $order += 10;
        }

        // Демонстрационные этапы, которых нет в боевой цепочке, скрываем, а не
        // удаляем: по ним может быть посчитана зарплата цеха.
        FactoryStage::query()
            ->ofPipeline(PipelineType::Factory)
            ->whereNotIn('code', array_column(self::STAGES, 'code'))
            ->update(['is_active' => false]);
    }
}
