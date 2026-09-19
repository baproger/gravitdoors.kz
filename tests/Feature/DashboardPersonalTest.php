<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BonusStatus;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\ProductionStatus;
use App\Enums\UserRole;
use App\Models\Bonus;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\DashboardStats;
use App\Support\Period;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Инфопанель по ролям: директор и финансы видят всё, остальные — «Мою работу»
 * с собственными цифрами и без сводок по чужим.
 */
class DashboardPersonalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_only_director_and_accountant_get_the_full_view(): void
    {
        $full = [UserRole::Admin, UserRole::Accountant];
        $personal = [UserRole::Manager, UserRole::Worker, UserRole::Master, UserRole::Surveyor, UserRole::Hr];

        foreach ($full as $role) {
            $stats = new DashboardStats(Period::make(Period::MONTH), User::factory()->create(['role' => $role->value]));
            $this->assertTrue($stats->isFullView(), $role->value);
            $this->assertFalse($stats->isPersonal(), $role->value);
            $this->assertTrue($stats->seesFactoryOverview(), $role->value);
        }

        foreach ($personal as $role) {
            $stats = new DashboardStats(Period::make(Period::MONTH), User::factory()->create(['role' => $role->value]));
            $this->assertFalse($stats->isFullView(), $role->value);
            $this->assertTrue($stats->isPersonal(), $role->value);
        }

        // Начальник производства ведёт весь цех — сводка по цеху у него полная.
        $master = new DashboardStats(Period::make(Period::MONTH), User::factory()->create(['role' => UserRole::Master->value]));
        $this->assertTrue($master->seesFactoryOverview());
    }

    public function test_worker_sees_own_stages_and_earnings_but_not_the_whole_shop(): void
    {
        $me = User::factory()->create(['role' => UserRole::Worker->value]);
        $colleague = User::factory()->create(['role' => UserRole::Worker->value]);
        $order = $this->order();
        $stage = FactoryStage::firstOf(PipelineType::Factory);

        $this->log($order, $stage, $me, ProductionStatus::Done, 3000);
        $this->log($order, $stage, $me, ProductionStatus::Done, 2500);
        $this->log($order, $stage, $colleague, ProductionStatus::Done, 9000);
        $this->log($this->order(), $stage, $me, ProductionStatus::InProgress, 0);

        $stats = new DashboardStats(Period::make(Period::MONTH), $me);

        $this->assertSame(2, $stats->myStagesClosed());
        $this->assertEqualsWithDelta(5500, $stats->myPiecework(), 0.01);
        $this->assertCount(1, $stats->myOrdersInProgress());
        $this->assertFalse($stats->seesFactoryOverview());

        $this->actingAs($me)->get('/admin')
            ->assertOk()
            ->assertSee('Закрыл этапов')
            ->assertSee('В работе у меня')
            ->assertDontSee('Загрузка цеха')
            ->assertDontSee('Выработка цеха')
            ->assertDontSee('Нарядов в цеху');
    }

    public function test_surveyor_sees_todays_measurements_with_address_and_no_money(): void
    {
        $surveyor = User::factory()->create(['role' => UserRole::Surveyor->value]);
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);

        $this->deal($manager, ['client_name' => 'Асель', 'client_address' => 'пр. Достык, 5', 'measured_at' => today()->setTime(11, 0), 'total_price' => 777_777]);
        $this->deal($manager, ['client_name' => 'Завтрашний', 'measured_at' => today()->addDay()->setTime(10, 0)]);
        $this->deal($manager, ['client_name' => 'Просроченный', 'measured_at' => today()->subDays(2)->setTime(10, 0)]);

        $stats = new DashboardStats(Period::make(Period::MONTH), $surveyor);
        $this->assertSame(1, $stats->myMeasurementsToday()->count());
        $this->assertSame(1, $stats->myMeasurementsAhead());
        $this->assertSame(1, $stats->myMeasurementsOverdue());

        $this->actingAs($surveyor)->get('/admin')
            ->assertOk()
            ->assertSee('Замеры сегодня')
            ->assertSee('11:00 — Асель')
            ->assertSee('пр. Достык, 5')
            ->assertDontSee('777 777')
            ->assertDontSee('Новых сделок');
    }

    public function test_manager_sees_own_bonus_and_measurements_and_no_manager_table(): void
    {
        $me = User::factory()->create(['role' => UserRole::Manager->value]);
        $other = User::factory()->create(['role' => UserRole::Manager->value]);

        Bonus::create(['user_id' => $me->id, 'month' => now()->format('Y-m'), 'amount' => 15_000, 'reason' => 'Сделка', 'status' => BonusStatus::Approved, 'created_by' => $me->id]);
        Bonus::create(['user_id' => $me->id, 'month' => now()->format('Y-m'), 'amount' => 4_000, 'reason' => 'Ждёт', 'status' => BonusStatus::Pending, 'created_by' => $me->id]);
        Bonus::create(['user_id' => $other->id, 'month' => now()->format('Y-m'), 'amount' => 90_000, 'reason' => 'Чужой', 'status' => BonusStatus::Approved, 'created_by' => $me->id]);

        $this->deal($me, ['client_name' => 'Мой замер', 'measured_at' => today()->setTime(15, 0)]);
        $this->deal($other, ['client_name' => 'Чужой замер', 'measured_at' => today()->setTime(16, 0)]);

        $stats = new DashboardStats(Period::make(Period::MONTH), $me);
        $this->assertEqualsWithDelta(15_000, $stats->myBonus(), 0.01);
        $this->assertEqualsWithDelta(4_000, $stats->myBonusPending(), 0.01);
        $this->assertSame(1, $stats->myMeasurementsToday()->count());

        $this->actingAs($me)->get('/admin')
            ->assertOk()
            ->assertSee('Мой бонус')
            ->assertSee('15 000')
            ->assertDontSee('90 000')
            ->assertSee('Мой замер')
            ->assertDontSee('Чужой замер')
            ->assertDontSee('Менеджеры ');
    }

    public function test_director_sees_everything_and_no_personal_block(): void
    {
        $director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->order();

        $this->actingAs($director)->get('/admin')
            ->assertOk()
            ->assertSee('Нарядов в цеху')
            ->assertSee('Загрузка цеха')
            ->assertSee('Новых сделок')
            ->assertDontSee('Закрыл этапов')
            ->assertDontSee('Мой бонус');
    }

    private function order(): Deal
    {
        $sales = $this->deal(User::factory()->create(['role' => UserRole::Manager->value]));

        return Deal::create([
            'title' => 'Наряд',
            'client_name' => $sales->client_name,
            'client_phone' => $sales->client_phone,
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InProduction,
            'pipeline_type' => PipelineType::Factory,
            'parent_deal_id' => $sales->id,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Factory)?->id,
            'production_started_at' => now(),
        ]);
    }

    private function log(Deal $order, FactoryStage $stage, User $worker, ProductionStatus $status, float $payout): ProductionLog
    {
        return ProductionLog::create([
            'deal_id' => $order->id,
            'stage_id' => $stage->id,
            'worker_id' => $worker->id,
            'started_at' => now()->subHours(3),
            'finished_at' => $status === ProductionStatus::Done ? now()->subHour() : null,
            'status' => $status,
            'payout' => $payout,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function deal(User $manager, array $attributes = []): Deal
    {
        return Deal::create(array_merge([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(3),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $manager->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'measurement')->value('id'),
        ], $attributes));
    }
}
