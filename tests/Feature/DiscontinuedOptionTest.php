<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\DoorOptionCategory;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DoorPriceCalculator;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Позицию сняли с продажи — старые заказы с ней не ломаются.
 *
 * Регрессия: выключенная позиция выпадала из расчёта (цена сделки падала при
 * пересохранении), не списывалась со склада, а форму сделки нельзя было
 * сохранить — выпадающий список не находил выбранное значение среди вариантов.
 */
class DiscontinuedOptionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->admin);
    }

    public function test_discontinued_option_is_not_offered_to_new_doors(): void
    {
        DoorOption::query()->where('code', 'lock_mottura')->update(['is_active' => false]);

        $calculator = app(DoorPriceCalculator::class);

        $this->assertArrayNotHasKey('lock_mottura', $calculator->optionsFor(DoorOptionCategory::LockSystem));

        $kept = $calculator->optionsFor(DoorOptionCategory::LockSystem, ['lock_mottura']);
        $this->assertArrayHasKey('lock_mottura', $kept);
        $this->assertStringContainsString('снята с продажи', $kept['lock_mottura']);
    }

    public function test_old_deal_keeps_its_price_and_still_saves(): void
    {
        $deal = $this->deal();
        app(DoorProductionService::class)->syncPricing($deal);
        $before = (float) $deal->refresh()->total_price;

        DoorOption::query()->where('code', 'lock_kale')->update(['is_active' => false]);

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertGreaterThan(0, $before);
        $this->assertEqualsWithDelta($before, (float) $deal->refresh()->total_price, 0.01);
    }

    public function test_materials_of_a_discontinued_option_are_still_written_off(): void
    {
        $deal = $this->deal();
        DoorOption::query()->where('code', 'lock_kale')->update(['is_active' => false]);

        app(DoorProductionService::class)->moveToStage(
            $deal,
            FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'handed_to_production')->firstOrFail(),
            $this->admin,
        );

        $lock = MaterialStock::query()->where('sku', 'LOCK-KALE')->firstOrFail();

        $this->assertTrue(
            StockMovement::query()->where('material_stock_id', $lock->id)->where('type', StockMovement::TYPE_OUT)->exists(),
            'Замок снятой с продажи позиции не списался со склада',
        );
    }

    private function deal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Архив», кв. 9',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
            'manager_id' => $this->admin->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-АРХ-9',
            'contract_date' => now()->subDays(2),
            'documents' => ['deals/contract.pdf'],
            'prepayment' => 50_000,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'category' => 'premium',
            'model' => 'lion',
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }
}
