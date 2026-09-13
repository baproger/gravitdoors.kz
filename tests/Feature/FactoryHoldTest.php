<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\ProductionException;
use App\Filament\Pages\SalesKanban;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Пока наряд в цеху, сделку продаж ведёт завод.
 *
 * Регрессия: сделку переводили на «Готово к отгрузке», пока её дверь была
 * ещё на покраске, — вручную на канбане или через поле «Этап» в карточке.
 */
class FactoryHoldTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->production = app(DoorProductionService::class);
        $this->manager = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->manager);
    }

    public function test_sales_deal_cannot_be_moved_forward_while_the_factory_works(): void
    {
        $deal = $this->dealInProduction();

        $this->expectException(ProductionException::class);
        $this->expectExceptionMessageMatches('/ждёт завод/u');

        $this->production->moveToStage($deal->refresh(), $this->stage('ready_to_ship'));
    }

    public function test_sales_deal_cannot_be_moved_back_while_the_factory_works(): void
    {
        $deal = $this->dealInProduction();

        $this->expectException(ProductionException::class);

        $this->production->moveToStage($deal->refresh(), $this->stage('contract'));
    }

    public function test_the_factory_still_moves_the_deal_when_production_is_done(): void
    {
        $deal = $this->dealInProduction();
        $order = $deal->refresh()->productionOrder;

        foreach (range(1, 6) as $_) {
            if ($order->refresh()->status_id->isClosed()) {
                break;
            }

            $this->production->completeCurrentStage($order);
        }

        $deal->refresh();

        $this->assertSame('ready_to_ship', $deal->currentStage->code);
        $this->assertSame(DealStatus::ReadyToShip, $deal->status_id);
        $this->assertNull($deal->activeProductionOrder());
    }

    public function test_deal_is_free_again_after_the_order_is_cancelled(): void
    {
        $deal = $this->dealInProduction();

        $this->production->cancelProduction($deal->refresh()->productionOrder, $this->manager, 'Клиент передумал');
        $this->production->moveToStage($deal->refresh(), $this->stage('contract'));

        $this->assertSame('contract', $deal->refresh()->currentStage->code);
    }

    public function test_kanban_arrow_does_not_push_a_waiting_deal(): void
    {
        $deal = $this->dealInProduction();

        Livewire::test(SalesKanban::class)->call('moveDeal', $deal->id, $this->stage('ready_to_ship')->id);

        $this->assertSame('handed_to_production', $deal->refresh()->currentStage->code);
    }

    public function test_kanban_card_shows_that_the_deal_waits_for_the_factory(): void
    {
        $this->dealInProduction();

        $this->get('/admin/kanban/sales')->assertOk()->assertSee('Ждёт завод');
    }

    public function test_deal_card_explains_the_lock(): void
    {
        $deal = $this->dealInProduction();

        $this->get("/admin/deals/{$deal->id}/edit")->assertOk()->assertSee('Сделка ждёт завод');
    }

    public function test_stage_field_in_the_card_cannot_bypass_the_funnel(): void
    {
        $deal = $this->readyDeal();

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['current_stage_id' => $this->stage('closed')->id])
            ->call('save');

        $this->assertSame('contract', $deal->refresh()->currentStage->code);
    }

    public function test_new_deal_still_starts_at_the_first_stage(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm([
                'title' => 'ЖК «Старт», кв. 1',
                'client_name' => 'Асхат Жумабеков',
                'client_phone' => '+7 (707) 111-22-33',
                'client_type' => 'individual',
                'doorConfigurations' => [[
                    'category' => 'premium', 'model' => 'lion', 'quantity' => 1,
                    'height' => 2050, 'width' => 950, 'opening_side' => 'right',
                    'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('new', Deal::query()->where('title', 'ЖК «Старт», кв. 1')->firstOrFail()->currentStage->code);
    }

    private function dealInProduction(): Deal
    {
        $deal = $this->readyDeal();

        $this->production->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);

        return $deal;
    }

    private function readyDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Завод», кв. 7',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            'manager_id' => $this->manager->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-ТЕСТ-7',
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

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
