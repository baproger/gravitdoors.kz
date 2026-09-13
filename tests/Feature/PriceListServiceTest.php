<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\DoorOptionCategory;
use App\Enums\PipelineType;
use App\Exceptions\PriceListException;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Services\PriceListService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Прайс нельзя поправить так, чтобы у уже оформленных заказов поехала цена. */
class PriceListServiceTest extends TestCase
{
    use RefreshDatabase;

    private PriceListService $prices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->prices = app(PriceListService::class);
    }

    public function test_new_option_gets_a_code_and_goes_to_the_end_of_its_group(): void
    {
        $option = $this->prices->create([
            'category' => 'lock_system',
            'label' => 'Замок Cisa',
            'price' => 52_000,
            'price_type' => 'fixed',
        ]);

        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $option->code);
        $this->assertSame(
            $option->id,
            DoorOption::query()->ofCategory(DoorOptionCategory::LockSystem)->orderByDesc('sort')->value('id'),
        );
    }

    public function test_codes_stay_unique_inside_a_group(): void
    {
        $first = $this->prices->create(['category' => 'additional', 'label' => 'Ручка', 'price' => 1, 'price_type' => 'fixed']);
        $second = $this->prices->create(['category' => 'additional', 'label' => 'Ручка!', 'price' => 1, 'price_type' => 'fixed']);

        $this->assertNotSame($first->code, $second->code);
    }

    public function test_negative_price_and_duplicate_label_are_rejected(): void
    {
        try {
            $this->prices->create(['category' => 'lock_system', 'label' => 'Замок', 'price' => -1, 'price_type' => 'fixed']);
            $this->fail('Отрицательная цена прошла');
        } catch (PriceListException) {
        }

        $this->expectException(PriceListException::class);
        $this->expectExceptionMessageMatches('/уже есть/u');

        $this->prices->create(['category' => 'lock_system', 'label' => 'kale 257r (1 ЗАМОК)', 'price' => 1, 'price_type' => 'fixed']);
    }

    public function test_update_never_changes_code_or_group(): void
    {
        $option = $this->option('metal_1_5');

        $this->prices->update($option, [
            'label' => 'Металл 1.5 мм',
            'price' => 13_000,
            'price_type' => 'per_sqm',
            'code' => 'hacked',
            'category' => 'additional',
        ]);

        $option->refresh();
        $this->assertSame('metal_1_5', $option->code);
        $this->assertSame(DoorOptionCategory::MetalThickness, $option->category);
        $this->assertEqualsWithDelta(13_000, (float) $option->price, 0.01);
    }

    public function test_default_is_single_per_group(): void
    {
        $this->prices->setDefault($this->option('lock_border'), true);

        $this->assertTrue($this->option('lock_border')->is_default);
        $this->assertFalse($this->option('lock_kale')->is_default);
        $this->assertSame(1, DoorOption::query()->ofCategory(DoorOptionCategory::LockSystem)->where('is_default', true)->count());
    }

    public function test_additional_options_have_no_default(): void
    {
        $this->expectException(PriceListException::class);

        $this->prices->setDefault($this->option('closer'), true);
    }

    public function test_discontinued_option_stops_being_the_default(): void
    {
        $this->prices->setActive($this->option('lock_kale'), false);

        $option = $this->option('lock_kale');
        $this->assertFalse($option->is_active);
        $this->assertFalse($option->is_default);

        $this->expectException(PriceListException::class);
        $this->prices->setDefault($option, true);
    }

    public function test_move_swaps_neighbours_inside_the_group(): void
    {
        $before = $this->codes(DoorOptionCategory::LockSystem);

        $this->prices->move($this->option($before[1]), -1);

        $after = $this->codes(DoorOptionCategory::LockSystem);
        $this->assertSame([$before[1], $before[0]], array_slice($after, 0, 2));
    }

    public function test_option_chosen_in_a_door_cannot_be_deleted(): void
    {
        $this->doorWith(['lock_system' => 'lock_kale']);

        $this->expectException(PriceListException::class);
        $this->expectExceptionMessageMatches('/выбрана в 1 двери/u');

        $this->prices->delete($this->option('lock_kale'));
    }

    public function test_additional_option_chosen_in_a_door_cannot_be_deleted(): void
    {
        $this->doorWith(['additional_options' => ['peephole', 'closer']]);

        $this->assertSame(1, $this->prices->usageOf($this->option('closer')));

        $this->expectException(PriceListException::class);

        $this->prices->delete($this->option('closer'));
    }

    public function test_unused_option_is_deleted(): void
    {
        $this->prices->delete($this->option('lock_mottura'));

        $this->assertDatabaseMissing('door_options', ['code' => 'lock_mottura']);
    }

    public function test_usage_map_counts_every_door(): void
    {
        $this->doorWith(['lock_system' => 'lock_kale', 'additional_options' => ['closer']]);
        $this->doorWith(['lock_system' => 'lock_kale']);

        $map = $this->prices->usageMap();

        $this->assertSame(2, $map['lock_system|lock_kale']);
        $this->assertSame(1, $map['additional|closer']);
    }

    private function option(string $code): DoorOption
    {
        return DoorOption::query()->where('code', $code)->firstOrFail();
    }

    /** @return list<string> */
    private function codes(DoorOptionCategory $category): array
    {
        return DoorOption::query()->ofCategory($category)->orderBy('sort')->orderBy('label')->orderBy('id')->pluck('code')->all();
    }

    /** @param array<string, mixed> $choice */
    private function doorWith(array $choice): DoorConfiguration
    {
        $deal = Deal::create([
            'title' => 'Сделка '.uniqid(),
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);

        return DoorConfiguration::create([
            'deal_id' => $deal->id,
            'category' => 'premium',
            'model' => 'lion',
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            ...$choice,
        ]);
    }
}
