<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\ProductionException;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\FactoryStageSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Блокировка отгрузки: финансы придерживают заказ до расчёта. */
class ShipmentBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $director;

    private DoorProductionService $production;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
        $this->production = app(DoorProductionService::class);
        $this->accountant = User::factory()->create(['role' => UserRole::Accountant->value]);
        $this->director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->accountant);
    }

    public function test_blocked_deal_does_not_move_past_production(): void
    {
        $deal = $this->readyToShipDeal();

        $this->production->setShipmentBlock($deal, true, 'Долг 320 000 ₸', $this->accountant);

        $this->assertTrue($deal->refresh()->isShipmentBlocked());

        $this->expectException(ProductionException::class);
        $this->expectExceptionMessageMatches('/заблокирована/u');

        $this->production->moveToStage($deal, $this->stage('delivery'), $this->director);
    }

    public function test_unblocking_lets_the_deal_go_again(): void
    {
        $deal = $this->readyToShipDeal();
        $this->production->setShipmentBlock($deal, true, 'Долг', $this->accountant);
        $this->production->setShipmentBlock($deal->refresh(), false, null, $this->accountant);

        $this->production->moveToStage($deal->refresh(), $this->stage('delivery'), $this->director);

        $this->assertSame('delivery', $deal->refresh()->currentStage->code);
        $this->assertTrue($deal->events()->where('description', 'Блокировка отгрузки снята')->exists());
    }

    public function test_block_needs_a_reason_and_does_not_apply_to_orders(): void
    {
        $deal = $this->readyToShipDeal();

        try {
            $this->production->setShipmentBlock($deal, true, '  ', $this->accountant);
            $this->fail('Блокировка без причины прошла');
        } catch (ValidationException) {
        }

        $this->assertFalse($deal->refresh()->isShipmentBlocked());
    }

    public function test_block_leaves_the_funnel_before_production_alone(): void
    {
        $deal = $this->readyToShipDeal();
        $deal->forceFill([
            'current_stage_id' => $this->stage('new')->id,
            'status_id' => DealStatus::InWork,
        ])->saveQuietly();
        $this->production->setShipmentBlock($deal->refresh(), true, 'Долг', $this->accountant);

        // До передачи в цех блокировка ничего не меняет: работа продолжается.
        $this->production->moveToStage($deal->refresh(), $this->stage('measurement'), $this->director);

        $this->assertSame('measurement', $deal->refresh()->currentStage->code);
    }

    public function test_only_finance_can_set_the_block(): void
    {
        $deal = $this->readyToShipDeal();
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $deal->forceFill(['manager_id' => $manager->id])->saveQuietly();

        $this->assertTrue($this->accountant->can('flagPayment', $deal));
        $this->assertFalse($manager->can('flagPayment', $deal->refresh()));

        Livewire::test(ListDeals::class)
            ->callAction(TestAction::make('blockShipment')->table($deal), data: ['reason' => 'Долг по договору'])
            ->assertHasNoErrors();

        $this->assertTrue($deal->refresh()->isShipmentBlocked());
        $this->assertSame($this->accountant->id, $deal->shipment_blocked_by);

        $this->actingAs($manager);
        Livewire::test(ListDeals::class)->assertActionHidden(TestAction::make('blockShipment')->table($deal));
    }

    public function test_block_is_visible_on_the_kanban(): void
    {
        $deal = $this->readyToShipDeal();
        $this->production->setShipmentBlock($deal, true, 'Долг 100 000 ₸', $this->accountant);

        $this->actingAs($this->director);
        $this->get('/admin/kanban/sales')->assertOk()->assertSee('Отгрузка заблокирована');
    }

    private function readyToShipDeal(): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Блок», кв. 3',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::ReadyToShip,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('ready_to_ship')->id,
            'manager_id' => User::factory()->create(['role' => UserRole::Manager->value])->id,
            'total_price' => 900_000,
            'prepayment' => 900_000,
            'due_date' => now()->addWeek(),
        ]);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
