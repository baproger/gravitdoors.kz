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

/** Журнал заходов восстанавливается из истории сделки один в один. */
class RebuildStageVisitsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_visits_are_rebuilt_from_stage_change_history(): void
    {
        $this->seed(FactoryStageSeeder::class);
        $manager = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->actingAs($manager);
        $production = app(DoorProductionService::class);

        Carbon::setTestNow('2026-09-13 17:15');
        $deal = Deal::create([
            'title' => 'Офис', 'client_name' => 'Клиент', 'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1', 'city' => 'Алматы', 'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales, 'current_stage_id' => $this->stage('contract')->id,
            'manager_id' => $manager->id, 'measured_at' => now()->subDay(), 'due_date' => now()->addWeeks(3),
        ]);
        DoorConfiguration::create(['deal_id' => $deal->id, 'category' => 'comfort', 'model' => 'agora', 'height' => 2050, 'width' => 950, 'opening_side' => 'right', 'quantity' => 1, 'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale']);

        Carbon::setTestNow('2026-09-15 08:43');
        $production->moveToStage($deal->refresh(), $this->stage('measurement'), $manager);
        Carbon::setTestNow('2026-09-15 08:50');
        $production->moveToStage($deal->refresh(), $this->stage('contract'), $manager);
        Carbon::setTestNow('2026-09-15 10:50');

        $expected = $deal->refresh()->hours_on_stage;
        $this->assertSame(41.5, $expected, '39,5 ч первого захода + 2 ч второго');

        // Журнал потерян (как у сделок, заведённых до его появления) — восстанавливаем.
        $deal->stageVisits()->delete();
        $this->assertSame(2.0, $deal->refresh()->hours_on_stage);

        $this->artisan('gravit:rebuild-stage-visits', ['--deal' => $deal->id])->assertSuccessful();

        $deal->refresh();
        $this->assertSame($expected, $deal->hours_on_stage);
        $this->assertSame(2, $deal->visitsOnCurrentStage());
        $this->assertSame(3, $deal->stageVisits()->count());
        $this->assertSame(1, $deal->stageVisits()->whereNull('left_at')->count());
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
