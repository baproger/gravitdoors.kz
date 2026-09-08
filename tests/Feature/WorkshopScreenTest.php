<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Livewire\WorkshopScreen;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\ProductionLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Экран цеха работает без логина, по коду, и не показывает денег. */
class WorkshopScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
    }

    public function test_screen_is_public_and_asks_for_a_code(): void
    {
        $this->get('/shop')->assertOk()->assertSee('Экран цеха');
    }

    public function test_wrong_code_is_rejected(): void
    {
        Livewire::test(WorkshopScreen::class)
            ->set('code', '000000')
            ->call('enter')
            ->assertSet('authorized', false)
            ->assertSet('error', 'Неверный код');
    }

    public function test_correct_code_opens_the_board(): void
    {
        $order = $this->makeOrder();

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->assertSet('authorized', true)
            ->assertSee('Раскрой металла')
            ->assertSee($order->number);
    }

    public function test_board_never_shows_money(): void
    {
        $order = $this->makeOrder();
        $order->update(['total_price' => 987654]);

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->assertDontSee('987 654')
            ->assertDontSee('Тестовый клиент');
    }

    public function test_completing_a_stage_moves_the_order_and_records_the_worker(): void
    {
        $order = $this->makeOrder();
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->call('chooseWorker', $worker->id)
            ->call('start', $order->id)
            ->call('complete', $order->id);

        $this->assertSame('welding', $order->refresh()->currentStage->code);

        $log = ProductionLog::query()
            ->where('deal_id', $order->id)
            ->whereNotNull('finished_at')
            ->firstOrFail();

        $this->assertSame($worker->id, $log->worker_id);
        $this->assertEqualsWithDelta(3000, (float) $log->payout, 0.01);
    }

    /**
     * Регрессия: <select> отдаёт строку, и строгий int в chooseWorker ронял
     * экран цеха с TypeError при первом же выборе исполнителя.
     */
    public function test_worker_can_be_chosen_from_the_select(): void
    {
        $this->makeOrder();
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->call('chooseWorker', (string) $worker->id)
            ->assertHasNoErrors()
            ->assertSet('workerId', $worker->id);
    }

    public function test_empty_choice_resets_the_worker(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->call('chooseWorker', (string) $worker->id)
            ->call('chooseWorker', '')
            ->assertSet('workerId', null);
    }

    public function test_a_stranger_cannot_be_set_as_the_worker(): void
    {
        // Менеджера в списке цеха нет — подставить его id снаружи не выйдет.
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);

        Livewire::test(WorkshopScreen::class)
            ->set('code', Setting::workshopCode())
            ->call('enter')
            ->call('chooseWorker', (string) $manager->id)
            ->assertSet('workerId', null);
    }

    public function test_actions_are_blocked_without_the_code(): void
    {
        $order = $this->makeOrder();
        $stageBefore = $order->current_stage_id;

        Livewire::test(WorkshopScreen::class)
            ->call('complete', $order->id)
            ->assertStatus(403);

        $this->assertSame($stageBefore, $order->refresh()->current_stage_id);
    }

    public function test_rotating_the_code_invalidates_the_old_one(): void
    {
        $old = Setting::workshopCode();
        $new = Setting::rotateWorkshopCode();

        $this->assertNotSame($old, $new);

        Livewire::test(WorkshopScreen::class)
            ->set('code', $old)
            ->call('enter')
            ->assertSet('authorized', false);
    }

    private function makeOrder(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Цех», кв. 7',
            'client_name' => 'Тестовый клиент',
            'client_phone' => '+7 700 000 00 00',
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

        return app(DoorProductionService::class)->handOffToProduction($deal->refresh());
    }
}
