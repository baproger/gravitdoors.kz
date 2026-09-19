<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\DoorOptionCategory;
use App\Enums\PipelineType;
use App\Enums\PriceType;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Services\DoorPriceCalculator;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoorPriceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private DoorPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        config()->set('gravit.pricing.assembly_cost', 0);
        config()->set('gravit.pricing.markup_percent', 0);
        config()->set('gravit.pricing.round_to', 0);

        $this->calculator = app(DoorPriceCalculator::class);
    }

    public function test_per_square_meter_option_scales_with_size(): void
    {
        // 2000 × 1000 мм = 2 м², металл 1.5 мм по 12 500 ₸/м² → 25 000 ₸.
        $breakdown = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
        ]);

        $this->assertSame(2.0, $breakdown->areaSqm);
        $this->assertEqualsWithDelta(25_000, $breakdown->optionsTotal, 0.01);
    }

    public function test_fixed_option_ignores_size(): void
    {
        $small = $this->calculator->calculate(['height' => 1800, 'width' => 800, 'lock_system' => 'lock_kale']);
        $large = $this->calculator->calculate(['height' => 2500, 'width' => 1200, 'lock_system' => 'lock_kale']);

        $this->assertEqualsWithDelta(18_000, $small->optionsTotal, 0.01);
        $this->assertEqualsWithDelta($small->optionsTotal, $large->optionsTotal, 0.01);
    }

    public function test_per_meter_option_uses_perimeter(): void
    {
        // Периметр 2 × (2000 + 1000) мм = 6 м.п. × 1 200 ₸ = 7 200 ₸.
        $breakdown = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'additional_options' => ['second_seal'],
        ]);

        $this->assertSame(6.0, $breakdown->perimeterMeters);
        $this->assertEqualsWithDelta(7_200, $breakdown->optionsTotal, 0.01);
    }

    public function test_quantity_multiplies_total_but_not_unit_price(): void
    {
        $one = $this->calculator->calculate(['height' => 2000, 'width' => 1000, 'metal_thickness' => 'metal_1_5']);
        $three = $this->calculator->calculate(['height' => 2000, 'width' => 1000, 'metal_thickness' => 'metal_1_5', 'quantity' => 3]);

        $this->assertEqualsWithDelta($one->unitPrice, $three->unitPrice, 0.01);
        $this->assertEqualsWithDelta($one->total * 3, $three->total, 0.01);
    }

    public function test_assembly_cost_and_markup_are_applied(): void
    {
        config()->set('gravit.pricing.assembly_cost', 10_000);
        config()->set('gravit.pricing.markup_percent', 10);

        // (25 000 металл + 10 000 сборка) × 1.10 = 38 500 ₸.
        $breakdown = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
        ]);

        $this->assertEqualsWithDelta(3_500, $breakdown->markupAmount, 0.01);
        $this->assertEqualsWithDelta(38_500, $breakdown->unitPrice, 0.01);
    }

    /** Позиция несёт только свои материалы: труд цеха считается на заказ. */
    public function test_position_cost_is_materials_only_and_scales_with_quantity(): void
    {
        // Металл: 0.36 листа/м² × 2 м² × 35 000 ₸ = 25 200 ₸ материалов на дверь.
        $one = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
        ]);

        $three = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
            'quantity' => 3,
        ]);

        $this->assertEqualsWithDelta(25_200, $one->materialsCost, 0.01);
        $this->assertEqualsWithDelta(75_600, $three->materialsCost, 0.01);
    }

    /**
     * Сдельная оплата — один раз на заказ, сколько бы дверей в нём ни было.
     *
     * Наряд проходит этап один раз и оплата за этап выплачивается один раз
     * (production_logs заводится на заказ). Пока труд умножался на количество,
     * заказ на три двери показывал тройную себестоимость труда при одинарной
     * выплате цеху — прибыль на таких заказах была занижена.
     */
    public function test_labour_is_counted_once_per_order_not_per_door(): void
    {
        // Сдельная оплата всех этапов цеха: 3000+6000+4500+5000+4000+2000 = 24 500 ₸.
        $labour = 24_500;
        $materials = 25_200;

        $deal = $this->dealWith(quantity: 3);
        $summary = $this->calculator->applyToDeal($deal);

        $this->assertSame(3, $summary->doorsCount());
        $this->assertEqualsWithDelta($materials * 3, $summary->materialsCost, 0.01);
        $this->assertEqualsWithDelta($labour, $summary->laborCost, 0.01, 'Труд не должен умножаться на двери');
        $this->assertEqualsWithDelta($materials * 3 + $labour, $summary->estimatedCost(), 0.01);

        // Две позиции по одной двери — тот же заказ, тот же труд один раз.
        $split = $this->calculator->applyToDeal($this->dealWith(quantity: 1, positions: 2));
        $this->assertEqualsWithDelta($labour, $split->laborCost, 0.01);
    }

    /**
     * Снятая с продажи позиция продолжает считаться там, где уже выбрана.
     *
     * Раньше выключенная позиция молча выпадала из расчёта, и при пересохранении
     * старой сделки её цена падала. Новым дверям такую позицию не предлагает
     * список вариантов — это проверяет DiscontinuedOptionTest.
     */
    public function test_discontinued_option_is_still_charged_where_already_chosen(): void
    {
        DoorOption::query()->where('code', 'metal_1_5')->update(['is_active' => false]);

        $breakdown = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
        ]);

        $this->assertCount(1, $breakdown->lines);
        $this->assertEqualsWithDelta(12_500 * 2, $breakdown->optionsTotal, 0.01);
    }

    public function test_rounding_step_is_respected(): void
    {
        config()->set('gravit.pricing.round_to', 1000);
        config()->set('gravit.pricing.assembly_cost', 1_234);

        $breakdown = $this->calculator->calculate([
            'height' => 2000,
            'width' => 1000,
            'metal_thickness' => 'metal_1_5',
        ]);

        $this->assertSame(0.0, fmod($breakdown->unitPrice, 1000.0));
    }

    public function test_options_for_category_returns_only_active_sorted(): void
    {
        $options = $this->calculator->optionsFor(DoorOptionCategory::LockSystem);

        $this->assertArrayHasKey('lock_kale', $options);
        $this->assertSame(PriceType::Fixed, DoorOption::query()->where('code', 'lock_kale')->firstOrFail()->price_type);
    }

    /** Сделка с дверьми одного вида: quantity в каждой позиции. */
    private function dealWith(int $quantity, int $positions = 1): Deal
    {
        $deal = Deal::create([
            'title' => 'Расчёт',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
        ]);

        foreach (range(1, $positions) as $ignored) {
            DoorConfiguration::create([
                'deal_id' => $deal->id,
                'category' => 'premium',
                'model' => 'lion',
                'height' => 2000,
                'width' => 1000,
                'quantity' => $quantity,
                'opening_side' => 'right',
                'metal_thickness' => 'metal_1_5',
            ]);
        }

        return $deal->refresh();
    }
}
