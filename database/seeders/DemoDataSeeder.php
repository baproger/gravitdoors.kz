<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DealStatus;
use App\Enums\OpeningSide;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Демонстрационные сделки: по одной в каждой колонке обеих воронок. */
class DemoDataSeeder extends Seeder
{
    public function run(DoorProductionService $production): void
    {
        $manager = User::firstOrCreate(
            ['email' => 'manager@gravit.kz'],
            ['name' => 'Айгуль Сериковна', 'password' => Hash::make('password'), 'role' => UserRole::Manager->value, 'phone' => '+7 701 000 00 02'],
        );

        $master = User::firstOrCreate(
            ['email' => 'master@gravit.kz'],
            ['name' => 'Ерлан Мастер', 'password' => Hash::make('password'), 'role' => UserRole::Master->value, 'phone' => '+7 701 000 00 03'],
        );

        $stages = FactoryStage::query()->ofPipeline(PipelineType::Sales)->ordered()->get()->keyBy('code');

        $clients = [
            ['ЖК «Алатау», кв. 45', 'Асхат Жумабеков', '+7 707 111 22 33', 'new', DealStatus::New],
            ['Частный дом, Каскелен', 'Марина Ким', '+7 705 444 55 66', 'measurement', DealStatus::InWork],
            ['ЖК «Керемет», кв. 112', 'Данияр Оспанов', '+7 777 888 99 00', 'contract', DealStatus::InWork],
            ['Офис на Абая 150', 'ТОО «Строй-Инвест»', '+7 727 355 66 77', 'contract', DealStatus::InWork],
        ];

        foreach ($clients as [$title, $client, $phone, $stageCode, $status]) {
            $deal = Deal::firstOrCreate(
                ['title' => $title],
                [
                    'client_name' => $client,
                    'client_phone' => $phone,
                    'status_id' => $status,
                    'pipeline_type' => PipelineType::Sales,
                    'current_stage_id' => $stages[$stageCode]->id,
                    'manager_id' => $manager->id,
                    'due_date' => now()->addDays(random_int(7, 25)),
                ],
            );

            DoorConfiguration::firstOrCreate(
                ['deal_id' => $deal->id, 'position' => 1],
                [
                    'label' => 'Входная в квартиру',
                    'height' => 2050,
                    'width' => random_int(0, 1) ? 950 : 860,
                    'opening_side' => random_int(0, 1) ? OpeningSide::Right->value : OpeningSide::Left->value,
                    'metal_thickness' => 'metal_1_5',
                    'outer_mdf_panel' => 'outer_mdf_16f',
                    'inner_mdf_panel' => 'inner_mdf_10',
                    'lock_system' => 'lock_kale_border',
                    'insulation_type' => 'ins_mineral',
                    'color_coating' => 'ral_powder',
                    'additional_options' => ['peephole', 'armor_plate'],
                    'quantity' => 1,
                ],
            );

            // У одной сделки — две двери: канбан и печатные формы должны
            // корректно вести себя не только на заказе из одной позиции.
            if ($title === 'Офис на Абая 150') {
                DoorConfiguration::firstOrCreate(
                    ['deal_id' => $deal->id, 'position' => 2],
                    [
                        'label' => 'Тамбурная',
                        'height' => 2050,
                        'width' => 1050,
                        'opening_side' => OpeningSide::Left->value,
                        'metal_thickness' => 'metal_1_2',
                        'outer_mdf_panel' => 'outer_mdf_10',
                        'inner_mdf_panel' => 'inner_none',
                        'lock_system' => 'lock_kale',
                        'insulation_type' => 'ins_eps',
                        'color_coating' => 'ral_powder',
                        'additional_options' => [],
                        'quantity' => 1,
                    ],
                );
            }

            $production->syncPricing($deal->refresh());
        }

        // Одна сделка проводится через автоматизацию целиком — чтобы на канбане
        // завода сразу были живые наряды на разных этапах.
        $inProduction = Deal::query()->sales()->where('title', 'ЖК «Керемет», кв. 112')->first();

        if ($inProduction && ! $inProduction->productionOrder()->exists()) {
            $production->moveToStage($inProduction, $stages['handed_to_production'], $manager);

            $order = $inProduction->productionOrder()->first();
            $production->startStage($order, $master);
            $production->completeCurrentStage($order, $master);
            $production->completeCurrentStage($order->refresh(), $master);
        }
    }
}
