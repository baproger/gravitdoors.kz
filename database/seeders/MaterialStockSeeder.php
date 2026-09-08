<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MaterialUnit;
use App\Models\MaterialStock;
use Illuminate\Database\Seeder;

class MaterialStockSeeder extends Seeder
{
    public function run(): void
    {
        $materials = [
            ['sku' => 'MET-12', 'name' => 'Лист стальной 1.2 мм (1250×2500)', 'unit' => MaterialUnit::Sheets, 'quantity' => 120, 'min_limit' => 20, 'price_per_unit' => 28_000],
            ['sku' => 'MET-15', 'name' => 'Лист стальной 1.5 мм (1250×2500)', 'unit' => MaterialUnit::Sheets, 'quantity' => 90, 'min_limit' => 20, 'price_per_unit' => 35_000],
            ['sku' => 'MET-20', 'name' => 'Лист стальной 2.0 мм (1250×2500)', 'unit' => MaterialUnit::Sheets, 'quantity' => 40, 'min_limit' => 15, 'price_per_unit' => 47_000],
            ['sku' => 'MDF-10', 'name' => 'МДФ-панель 10 мм гладкая', 'unit' => MaterialUnit::SquareMeters, 'quantity' => 260, 'min_limit' => 50, 'price_per_unit' => 6_500],
            ['sku' => 'MDF-16F', 'name' => 'МДФ-панель 16 мм фрезерованная', 'unit' => MaterialUnit::SquareMeters, 'quantity' => 180, 'min_limit' => 40, 'price_per_unit' => 9_800],
            ['sku' => 'MDF-16D', 'name' => 'МДФ-панель 16 мм 3D', 'unit' => MaterialUnit::SquareMeters, 'quantity' => 75, 'min_limit' => 25, 'price_per_unit' => 14_500],
            ['sku' => 'INS-MW', 'name' => 'Минеральная вата 50 мм', 'unit' => MaterialUnit::SquareMeters, 'quantity' => 400, 'min_limit' => 80, 'price_per_unit' => 1_400],
            ['sku' => 'INS-EPS', 'name' => 'Пенополистирол 50 мм', 'unit' => MaterialUnit::SquareMeters, 'quantity' => 320, 'min_limit' => 60, 'price_per_unit' => 950],
            ['sku' => 'INS-PU', 'name' => 'Пенополиуретан напыляемый', 'unit' => MaterialUnit::Liters, 'quantity' => 140, 'min_limit' => 30, 'price_per_unit' => 2_100],
            ['sku' => 'LOCK-KALE', 'name' => 'Замок Kale 257R', 'unit' => MaterialUnit::Pieces, 'quantity' => 65, 'min_limit' => 15, 'price_per_unit' => 9_500],
            ['sku' => 'LOCK-BRD', 'name' => 'Замок Border ЗВ8', 'unit' => MaterialUnit::Pieces, 'quantity' => 48, 'min_limit' => 12, 'price_per_unit' => 13_000],
            ['sku' => 'LOCK-MOT', 'name' => 'Замок Mottura 54.797', 'unit' => MaterialUnit::Pieces, 'quantity' => 12, 'min_limit' => 5, 'price_per_unit' => 42_000],
            ['sku' => 'PWD-RAL', 'name' => 'Порошковая краска RAL', 'unit' => MaterialUnit::Kilograms, 'quantity' => 210, 'min_limit' => 50, 'price_per_unit' => 2_300],
            ['sku' => 'PWD-ANT', 'name' => 'Порошковая краска «антик»', 'unit' => MaterialUnit::Kilograms, 'quantity' => 85, 'min_limit' => 25, 'price_per_unit' => 3_400],
            ['sku' => 'SEAL', 'name' => 'Уплотнитель контурный', 'unit' => MaterialUnit::Meters, 'quantity' => 900, 'min_limit' => 150, 'price_per_unit' => 320],
            ['sku' => 'HNG', 'name' => 'Петля усиленная на подшипнике', 'unit' => MaterialUnit::Pieces, 'quantity' => 220, 'min_limit' => 40, 'price_per_unit' => 2_600],
            ['sku' => 'EYE', 'name' => 'Глазок широкоугольный', 'unit' => MaterialUnit::Pieces, 'quantity' => 95, 'min_limit' => 20, 'price_per_unit' => 2_800],
            ['sku' => 'CLS', 'name' => 'Доводчик дверной', 'unit' => MaterialUnit::Pieces, 'quantity' => 18, 'min_limit' => 10, 'price_per_unit' => 7_400],
            ['sku' => 'ARM', 'name' => 'Броненакладка на замок', 'unit' => MaterialUnit::Pieces, 'quantity' => 34, 'min_limit' => 10, 'price_per_unit' => 6_900],
        ];

        foreach ($materials as $material) {
            MaterialStock::updateOrCreate(
                ['sku' => $material['sku']],
                [...$material, 'unit' => $material['unit']->value, 'is_active' => true],
            );
        }
    }
}
