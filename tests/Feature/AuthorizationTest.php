<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FactoryKanban;
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

/** Цех не должен видеть деньги, а менеджер — трогать настройки воронок. */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
    }

    public function test_workshop_staff_cannot_open_sales_kanban(): void
    {
        $this->actingAs($this->user(UserRole::Worker));

        $this->get('/admin/kanban/sales')->assertForbidden();
    }

    public function test_workshop_staff_cannot_open_calculator(): void
    {
        $this->actingAs($this->user(UserRole::Master));

        $this->get('/admin/calculator')->assertForbidden();
    }

    public function test_only_admin_configures_pipeline_stages(): void
    {
        $this->actingAs($this->user(UserRole::Manager));
        $this->get('/admin/factory-stages')->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin, 'admin2@gravit.kz'));
        $this->get('/admin/factory-stages')->assertOk();
    }

    public function test_master_deal_list_contains_only_production_orders(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);

        $this->actingAs($this->user(UserRole::Master));

        $this->get('/admin/deals')
            ->assertOk()
            ->assertSee($order->number)
            ->assertDontSee($deal->number);
    }

    public function test_master_does_not_see_order_amount(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['total_price' => 987654]);
        $order = app(DoorProductionService::class)->handOffToProduction($deal);
        $order->update(['total_price' => 987654]);

        $this->actingAs($this->user(UserRole::Master));

        // «987 654 ₸» не должно быть нигде: ни в колонке, ни в разметке.
        $this->get('/admin/deals')->assertOk()->assertDontSee('987 654');
    }

    public function test_manager_still_sees_amounts(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['total_price' => 987654]);

        $this->actingAs($this->user(UserRole::Manager));

        $this->get('/admin/deals')->assertOk()->assertSee('987 654');
    }

    public function test_worker_cannot_move_a_sales_deal_through_the_factory_board(): void
    {
        $deal = $this->makeDeal();
        $stageBefore = $deal->current_stage_id;
        $target = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'handed_to_production')->firstOrFail();

        // Доска завода рабочему доступна, поэтому вызов moveDeal он сделать может —
        // и именно поэтому решение принимает политика на сервере.
        $this->actingAs($this->user(UserRole::Worker));

        Livewire::test(FactoryKanban::class)->call('moveDeal', $deal->id, $target->id);

        $this->assertSame($stageBefore, $deal->refresh()->current_stage_id);
        $this->assertNull($deal->productionOrder()->first(), 'Наряд не должен был появиться');
    }

    public function test_master_can_move_a_production_order(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);
        $welding = FactoryStage::query()->ofPipeline(PipelineType::Factory)->where('code', 'welding')->firstOrFail();

        $this->actingAs($this->user(UserRole::Master));

        Livewire::test(FactoryKanban::class)->call('moveDeal', $order->id, $welding->id);

        $this->assertSame($welding->id, $order->refresh()->current_stage_id);
    }

    public function test_admin_passes_every_policy(): void
    {
        $admin = $this->user(UserRole::Admin, 'boss@gravit.kz');
        $deal = $this->makeDeal();

        $this->assertTrue($admin->can('delete', $deal));
        $this->assertTrue($admin->can('viewAny', FactoryStage::class));
    }

    private function user(UserRole $role, string $email = 'staff@gravit.kz'): User
    {
        return User::factory()->create([
            'email' => $email.$role->value,
            'role' => $role->value,
            'is_active' => true,
        ]);
    }

    private function makeDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'Сделка для проверки прав',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'position' => 1,
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
