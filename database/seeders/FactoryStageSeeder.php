<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PipelineType;
use App\Enums\StageRequirement as Req;
use App\Models\FactoryStage;
use Illuminate\Database\Seeder;

/**
 * Стартовая настройка обеих воронок. Дальше этапы редактируются в админке:
 * названия, порядок, нормативы часов и сдельные расценки — без правки кода.
 */
class FactoryStageSeeder extends Seeder
{
    public function run(): void
    {
        // Регламент отдела продаж: без этих полей сделку на этап не пустят.
        $sales = [
            [
                'code' => 'new', 'name' => 'Новая заявка', 'color' => 'gray', 'icon' => 'heroicon-o-inbox',
                'is_initial' => true,
                'description' => 'Заявка принята, менеджер связывается с клиентом',
            ],
            [
                'code' => 'measurement', 'name' => 'Замер и расчёт', 'color' => 'info',
                'icon' => 'heroicon-o-calculator', 'estimated_hours' => 24,
                'description' => 'Выезд замерщика и расчёт спецификации',
                'required' => [Req::ClientPhone, Req::ClientAddress, Req::City, Req::Manager],
            ],
            [
                'code' => 'contract', 'name' => 'Договор и предоплата', 'color' => 'primary',
                'icon' => 'heroicon-o-document-text', 'estimated_hours' => 48,
                'description' => 'Согласование спецификации и подписание договора',
                'required' => [Req::MeasuredAt, Req::Doors, Req::DueDate, Req::CompanyDetails],
            ],
            [
                'code' => 'handed_to_production', 'name' => 'Передано в производство', 'color' => 'warning',
                'icon' => 'heroicon-o-arrow-right-circle', 'triggers_production' => true,
                'description' => 'Наряд уходит на завод, материалы списываются со склада',
                'required' => [Req::ContractNumber, Req::ContractDate, Req::Documents, Req::Prepayment],
            ],
            [
                'code' => 'ready_to_ship', 'name' => 'Готово к отгрузке', 'color' => 'success',
                'icon' => 'heroicon-o-cube',
                'description' => 'Двери готовы и упакованы, ждут логистику',
            ],
            [
                'code' => 'delivery', 'name' => 'Доставка и монтаж', 'color' => 'info',
                'icon' => 'heroicon-o-truck', 'estimated_hours' => 48,
                'description' => 'Доставка на объект и установка',
            ],
            [
                'code' => 'closed', 'name' => 'Сделка закрыта', 'color' => 'success',
                'icon' => 'heroicon-o-check-badge', 'is_final' => true,
                'description' => 'Работы приняты, оплата получена полностью',
                'required' => [Req::PaidInFull],
            ],
        ];

        $factory = [
            ['code' => 'metal_cutting', 'name' => 'Раскрой металла', 'color' => 'gray', 'icon' => 'heroicon-o-scissors', 'estimated_hours' => 2, 'operation_cost' => 3000, 'is_initial' => true],
            ['code' => 'welding', 'name' => 'Сварка каркаса', 'color' => 'warning', 'icon' => 'heroicon-o-fire', 'estimated_hours' => 4, 'operation_cost' => 6000],
            ['code' => 'painting', 'name' => 'Грунтовка и покраска', 'color' => 'info', 'icon' => 'heroicon-o-paint-brush', 'estimated_hours' => 3, 'operation_cost' => 4500],
            ['code' => 'insulation_mdf', 'name' => 'Утепление и МДФ-панели', 'color' => 'primary', 'icon' => 'heroicon-o-square-3-stack-3d', 'estimated_hours' => 3, 'operation_cost' => 5000],
            ['code' => 'hardware', 'name' => 'Фурнитура и замки', 'color' => 'info', 'icon' => 'heroicon-o-key', 'estimated_hours' => 2, 'operation_cost' => 4000],
            ['code' => 'qc_packing', 'name' => 'ОТК и Упаковка', 'color' => 'success', 'icon' => 'heroicon-o-shield-check', 'estimated_hours' => 1, 'operation_cost' => 2000, 'completes_production' => true, 'is_final' => true],
        ];

        $this->store(PipelineType::Sales, $sales);
        $this->store(PipelineType::Factory, $factory);
    }

    /** @param list<array<string, mixed>> $stages */
    private function store(PipelineType $pipeline, array $stages): void
    {
        foreach ($stages as $index => $stage) {
            FactoryStage::updateOrCreate(
                ['pipeline_type' => $pipeline->value, 'code' => $stage['code']],
                [
                    'name' => $stage['name'],
                    'order' => ($index + 1) * 10,
                    'estimated_hours' => $stage['estimated_hours'] ?? 0,
                    'operation_cost' => $stage['operation_cost'] ?? 0,
                    'color' => $stage['color'] ?? 'gray',
                    'icon' => $stage['icon'] ?? null,
                    'is_initial' => $stage['is_initial'] ?? false,
                    'is_final' => $stage['is_final'] ?? false,
                    'triggers_production' => $stage['triggers_production'] ?? false,
                    'completes_production' => $stage['completes_production'] ?? false,
                    'required_fields' => array_map(
                        fn (Req $requirement): string => $requirement->value,
                        $stage['required'] ?? [],
                    ),
                    'is_active' => true,
                ],
            );
        }
    }
}
