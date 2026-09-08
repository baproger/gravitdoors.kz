<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DoorOptionCategory as Cat;
use App\Enums\PriceType;
use App\Models\DoorOption;
use App\Models\MaterialStock;
use Illuminate\Database\Seeder;

/**
 * Прайс конфигуратора. `consumption` — расход материала на единицу базы расчёта:
 * для per_sqm это расход на 1 м² полотна, для per_meter — на 1 м.п. периметра,
 * для fixed — на изделие. Из него сервис списывает склад при передаче в цех.
 */
class DoorOptionSeeder extends Seeder
{
    public function run(): void
    {
        $sku = MaterialStock::query()->pluck('id', 'sku');

        $options = [
            // Металл: лист 1250×2500 = 3.125 м², с учётом раскроя ≈ 0.36 листа на м².
            [Cat::MetalThickness, 'metal_1_2', 'Металл 1.2 мм', 9_000, PriceType::PerSquareMeter, 'MET-12', 0.36, true],
            [Cat::MetalThickness, 'metal_1_5', 'Металл 1.5 мм', 12_500, PriceType::PerSquareMeter, 'MET-15', 0.36],
            [Cat::MetalThickness, 'metal_2_0', 'Металл 2.0 мм', 16_500, PriceType::PerSquareMeter, 'MET-20', 0.36],

            [Cat::OuterMdfPanel, 'outer_none', 'Без наружной панели', 0, PriceType::Fixed, null, 0],
            [Cat::OuterMdfPanel, 'outer_mdf_10', 'МДФ 10 мм гладкая', 12_000, PriceType::PerSquareMeter, 'MDF-10', 1.1, true],
            [Cat::OuterMdfPanel, 'outer_mdf_16f', 'МДФ 16 мм фрезерованная', 18_000, PriceType::PerSquareMeter, 'MDF-16F', 1.1],
            [Cat::OuterMdfPanel, 'outer_mdf_16d', 'МДФ 16 мм 3D-фрезеровка', 24_000, PriceType::PerSquareMeter, 'MDF-16D', 1.1],

            [Cat::InnerMdfPanel, 'inner_none', 'Без внутренней панели', 0, PriceType::Fixed, null, 0],
            [Cat::InnerMdfPanel, 'inner_mdf_10', 'МДФ 10 мм гладкая', 9_500, PriceType::PerSquareMeter, 'MDF-10', 1.1, true],
            [Cat::InnerMdfPanel, 'inner_mdf_16f', 'МДФ 16 мм фрезерованная', 14_500, PriceType::PerSquareMeter, 'MDF-16F', 1.1],

            [Cat::LockSystem, 'lock_kale', 'Kale 257R (1 замок)', 18_000, PriceType::Fixed, 'LOCK-KALE', 1, true],
            [Cat::LockSystem, 'lock_border', 'Border ЗВ8 (1 замок)', 25_000, PriceType::Fixed, 'LOCK-BRD', 1],
            [Cat::LockSystem, 'lock_kale_border', 'Kale + Border (2 замка)', 40_000, PriceType::Fixed, 'LOCK-BRD', 1],
            [Cat::LockSystem, 'lock_mottura', 'Mottura 54.797 (премиум)', 68_000, PriceType::Fixed, 'LOCK-MOT', 1],

            [Cat::InsulationType, 'ins_mineral', 'Минеральная вата', 3_500, PriceType::PerSquareMeter, 'INS-MW', 1.05, true],
            [Cat::InsulationType, 'ins_eps', 'Пенополистирол', 2_500, PriceType::PerSquareMeter, 'INS-EPS', 1.05],
            [Cat::InsulationType, 'ins_pu', 'Пенополиуретан (напыление)', 5_500, PriceType::PerSquareMeter, 'INS-PU', 0.8],

            [Cat::ColorCoating, 'ral_powder', 'Порошковая покраска RAL', 6_000, PriceType::PerSquareMeter, 'PWD-RAL', 0.25, true],
            [Cat::ColorCoating, 'antique', 'Патина «антик»', 8_500, PriceType::PerSquareMeter, 'PWD-ANT', 0.25],
            [Cat::ColorCoating, 'muar', 'Муар (шагрень)', 7_000, PriceType::PerSquareMeter, 'PWD-RAL', 0.28],

            [Cat::Additional, 'closer', 'Доводчик', 12_000, PriceType::Fixed, 'CLS', 1],
            [Cat::Additional, 'peephole', 'Глазок широкоугольный', 5_000, PriceType::Fixed, 'EYE', 1],
            [Cat::Additional, 'armor_plate', 'Броненакладка', 15_000, PriceType::Fixed, 'ARM', 1],
            [Cat::Additional, 'thermal_break', 'Терморазрыв полотна', 9_000, PriceType::PerSquareMeter, null, 0],
            [Cat::Additional, 'second_seal', 'Второй контур уплотнения', 1_200, PriceType::PerMeter, 'SEAL', 1.1],
        ];

        foreach ($options as $index => $option) {
            [$category, $code, $label, $price, $priceType, $materialSku, $consumption] = $option;

            DoorOption::updateOrCreate(
                ['category' => $category->value, 'code' => $code],
                [
                    'label' => $label,
                    'price' => $price,
                    'price_type' => $priceType->value,
                    'material_stock_id' => $materialSku ? $sku[$materialSku] ?? null : null,
                    'consumption' => $consumption,
                    'sort' => ($index + 1) * 10,
                    'is_default' => $option[7] ?? false,
                    'is_active' => true,
                ],
            );
        }
    }
}
