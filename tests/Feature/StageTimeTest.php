<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Carbon\Carbon;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Время на этапе копится за все заходы.
 *
 * Регрессия: сделка простояла на «Договоре» 34,5 ч, её увели на другой этап и
 * вернули — таймер начал с нуля, часы пропали.
 */
class StageTimeTest extends TestCase
{
    use RefreshDatabase;

    private DoorProductionService $production;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FactoryStageSeeder::class);
        $this->production = app(DoorProductionService::class);
        $this->manager = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_hours_survive_a_round_trip_to_another_stage(): void
    {
        Carbon::setTestNow('2026-09-10 08:00');
        $deal = $this->deal('contract');

        Carbon::setTestNow('2026-09-11 18:30');                              // 34,5 ч на договоре
        $this->assertSame(34.5, $deal->refresh()->hours_on_stage);

        $this->production->moveToStage($deal, $this->stage('measurement'), $this->manager);
        Carbon::setTestNow('2026-09-12 18:30');                              // сутки на замере
        $this->assertSame(24.0, $deal->refresh()->hours_on_stage);

        $this->production->moveToStage($deal->refresh(), $this->stage('contract'), $this->manager);
        Carbon::setTestNow('2026-09-12 20:30');                              // ещё 2 ч на договоре

        $deal->refresh();
        $this->assertSame(36.5, $deal->hours_on_stage, '34,5 ч первого захода + 2 ч второго');
        $this->assertSame(2.0, $deal->hoursOnCurrentVisit());
        $this->assertSame(2, $deal->visitsOnCurrentStage());

        $byStage = $deal->hoursByStage();
        $this->assertSame(36.5, $byStage[$this->stage('contract')->id]);
        $this->assertSame(24.0, $byStage[$this->stage('measurement')->id]);
        $this->assertSame(3, $deal->stageVisits()->count());
        $this->assertSame(1, $deal->stageVisits()->whereNull('left_at')->count(), 'Открыт ровно один заход');
    }

    public function test_stage_norm_counts_accumulated_time(): void
    {
        // Норматив «Замер и расчёт» — 24 ч: 20 ч в первый заход + 6 во второй = просрочка.
        Carbon::setTestNow('2026-09-10 08:00');
        $deal = $this->deal('measurement');

        Carbon::setTestNow('2026-09-11 04:00');
        $this->production->moveToStage($deal, $this->stage('new'), $this->manager);
        $this->production->moveToStage($deal->refresh(), $this->stage('measurement'), $this->manager);
        $this->assertFalse($deal->refresh()->isStageOverdue());

        Carbon::setTestNow('2026-09-11 10:00');
        $this->assertTrue($deal->refresh()->isStageOverdue());
        $this->assertSame(2.0, $deal->stageOverdueHours());
    }

    public function test_card_shows_the_second_visit(): void
    {
        Carbon::setTestNow('2026-09-10 08:00');
        $deal = $this->deal('contract');
        Carbon::setTestNow('2026-09-11 08:00');
        $this->production->moveToStage($deal, $this->stage('measurement'), $this->manager);
        $this->production->moveToStage($deal->refresh(), $this->stage('contract'), $this->manager);
        Carbon::setTestNow('2026-09-11 10:00');

        $this->get("/admin/deals/{$deal->id}/edit")->assertOk()->assertSee('26 ч ↺2');
        $this->get('/admin/kanban/sales')->assertOk()->assertSee('26 ч')->assertSee('↺2');
    }

    public function test_existing_deals_get_an_open_visit_from_their_entry_time(): void
    {
        // Миграция бэкфилла: сделка, заведённая тихо, без наблюдателя.
        Carbon::setTestNow('2026-09-10 08:00');
        $deal = $this->deal('contract');
        $deal->stageVisits()->delete();

        Carbon::setTestNow('2026-09-10 20:00');
        // Без журнала — только текущий вход, ничего не теряем и не выдумываем.
        $this->assertSame(12.0, $deal->refresh()->hours_on_stage);
        $this->assertSame(1, $deal->visitsOnCurrentStage());
    }

    private function deal(string $stage): Deal
    {
        $deal = Deal::create([
            'title' => 'Сделка со временем',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage($stage)->id,
            'manager_id' => $this->manager->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(3),
            'stage_entered_at' => now(),
        ]);

        // Без двери регламент не пустит сделку вперёд на «Договор».
        DoorConfiguration::create([
            'deal_id' => $deal->id, 'category' => 'comfort', 'model' => 'agora',
            'height' => 2050, 'width' => 950, 'opening_side' => 'right', 'quantity' => 1,
            'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
