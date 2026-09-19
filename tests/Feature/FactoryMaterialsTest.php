<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\ProductionException;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Цех отмечает фактический расход: списание сверх плана и возврат излишка. */
class FactoryMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    private Deal $order;

    private MaterialStock $material;

    private DoorProductionService $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->production = app(DoorProductionService::class);
        $this->master = User::factory()->create(['role' => UserRole::Master->value]);
        $this->material = MaterialStock::query()->firstOrFail();
        $this->order = $this->makeOrder();

        $this->actingAs($this->master);
    }

    public function test_extra_write_off_lowers_the_stock_and_lands_in_history(): void
    {
        $before = (float) $this->material->refresh()->quantity;

        $this->production->adjustMaterials($this->order, $this->material->id, 2, StockMovement::TYPE_OUT, 'Брак листа', $this->master);

        $this->assertEqualsWithDelta($before - 2, (float) $this->material->refresh()->quantity, 0.001);

        $movement = StockMovement::query()->where('deal_id', $this->order->id)->latest('id')->firstOrFail();
        $this->assertSame(StockMovement::TYPE_OUT, $movement->type);
        $this->assertSame($this->master->id, $movement->user_id);
        $this->assertStringContainsString('Брак листа', $movement->comment);

        $this->assertTrue($this->order->parentDeal->events()
            ->where('description', 'like', 'Списано сверх плана%')->exists());
    }

    public function test_leftovers_go_back_to_the_warehouse(): void
    {
        $before = (float) $this->material->refresh()->quantity;

        $this->production->adjustMaterials($this->order, $this->material->id, 1.5, StockMovement::TYPE_IN, null, $this->master);

        $this->assertEqualsWithDelta($before + 1.5, (float) $this->material->refresh()->quantity, 0.001);
    }

    public function test_zero_and_closed_orders_are_refused(): void
    {
        try {
            $this->production->adjustMaterials($this->order, $this->material->id, 0, StockMovement::TYPE_OUT, null, $this->master);
            $this->fail('Ноль прошёл');
        } catch (ValidationException) {
        }

        $this->production->cancelProduction($this->order->refresh(), $this->master, 'Отказ');

        $this->expectException(ProductionException::class);
        $this->production->adjustMaterials($this->order->refresh(), $this->material->id, 1, StockMovement::TYPE_OUT, null, $this->master);
    }

    public function test_action_is_offered_to_the_workshop_only(): void
    {
        // Цех видит действие на наряде…
        Livewire::test(ListDeals::class)
            ->assertActionVisible(TestAction::make('factoryMaterials')->table($this->order));

        // …а финансы и продажи — нет: склад ведёт производство.
        foreach ([UserRole::Accountant, UserRole::Manager] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));

            $this->assertFalse(
                AccessControl::can(Permission::FactoryMaterials, AccessLevel::Full),
                $role->getLabel(),
            );
        }
    }

    private function makeOrder(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Факт», кв. 2',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 2',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'manager_id' => User::factory()->create(['role' => UserRole::Manager->value])->id,
            'due_date' => now()->addWeeks(2),
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

        return $this->production->handOffToProduction($deal->refresh(), $this->master);
    }
}
