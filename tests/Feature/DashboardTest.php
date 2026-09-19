<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Expense;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\DashboardStats;
use App\Support\Period;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Инфопанель: период, показатели и права на них. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, CashAccountSeeder::class]);

        $this->director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->actingAs($this->director);
    }

    public function test_dashboard_opens_for_every_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));
            $this->get('/admin')->assertOk();
        }
    }

    public function test_period_switch_changes_the_numbers(): void
    {
        $this->deal('Свежая', $this->manager, 500_000);
        $old = $this->deal('Прошлогодняя', $this->manager, 700_000);
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        $month = new DashboardStats(Period::make(Period::MONTH), $this->director);
        $all = new DashboardStats(Period::make(Period::ALL), $this->director);

        $this->assertSame(1, $month->newDeals());
        $this->assertSame(2, $all->newDeals());
        $this->assertEqualsWithDelta(500_000, $month->newDealsSum(), 0.01);
        $this->assertEqualsWithDelta(1_200_000, $all->newDealsSum(), 0.01);
    }

    public function test_custom_range_and_swapped_bounds(): void
    {
        $deal = $this->deal('В окне', $this->manager, 100_000);
        $deal->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $inside = Period::make(Period::CUSTOM, now()->subDays(15)->toDateString(), now()->subDays(5)->toDateString());
        $outside = Period::make(Period::CUSTOM, now()->subDays(3)->toDateString(), now()->toDateString());

        $this->assertSame(1, (new DashboardStats($inside, $this->director))->newDeals());
        $this->assertSame(0, (new DashboardStats($outside, $this->director))->newDeals());

        // Границы задом наперёд — период всё равно считается.
        $swapped = Period::make(Period::CUSTOM, now()->subDays(5)->toDateString(), now()->subDays(15)->toDateString());
        $this->assertSame(1, (new DashboardStats($swapped, $this->director))->newDeals());
    }

    public function test_money_and_profit_are_counted_for_the_period(): void
    {
        $deal = $this->deal('С оплатой', $this->manager, 300_000);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 120_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/1.jpg']);
        Expense::factory()->approved()->create(['amount' => 45_000, 'spent_at' => now()->toDateString()]);

        $stats = new DashboardStats(Period::make(Period::MONTH), $this->director);

        $this->assertEqualsWithDelta(120_000, $stats->income(), 0.01);
        $this->assertEqualsWithDelta(120_000, $stats->incomeCash(), 0.01);
        $this->assertEqualsWithDelta(45_000, $stats->expenses(), 0.01);
        $this->assertEqualsWithDelta(75_000, $stats->profit(), 0.01);
        $this->assertEqualsWithDelta(180_000, $stats->receivables(), 0.01);
    }

    public function test_manager_sees_only_own_numbers_and_no_finance_block(): void
    {
        $this->deal('Моя', $this->manager, 400_000);
        $this->deal('Чужая', $this->director, 900_000);

        $stats = new DashboardStats(Period::make(Period::MONTH), $this->manager);

        $this->assertSame(1, $stats->newDeals());
        $this->assertEqualsWithDelta(400_000, $stats->newDealsSum(), 0.01);
        $this->assertTrue($stats->seesSales());
        $this->assertTrue($stats->seesMoney(), 'Суммы своих сделок менеджер видит');
        $this->assertFalse($stats->seesFinance(), 'Финансовый блок ему не положен');
        $this->assertFalse($stats->seesTotals(), 'Сводка по менеджерам — не для менеджера');

        $this->actingAs($this->manager);
        $this->get('/admin')->assertOk()->assertSee('Моя')->assertDontSee('Чужая');
    }

    public function test_workshop_sees_production_without_money(): void
    {
        $master = User::factory()->create(['role' => UserRole::Master->value]);
        $stats = new DashboardStats(Period::make(Period::MONTH), $master);

        $this->assertTrue($stats->seesFactory());
        $this->assertTrue($stats->seesWarehouse());
        $this->assertFalse($stats->seesSales());
        $this->assertFalse($stats->seesMoney());
        $this->assertFalse($stats->seesFinance());
    }

    public function test_page_keeps_the_period_in_the_url(): void
    {
        Livewire::test(Dashboard::class)
            ->call('setPeriod', Period::QUARTER)
            ->assertSet('period', Period::QUARTER)
            ->call('setPeriod', 'ерунда')
            ->assertSet('period', Period::MONTH);

        $this->get('/admin?period='.Period::YEAR)->assertOk()->assertSee('за год');
    }

    private function deal(string $title, User $owner, float $total): Deal
    {
        return Deal::create([
            'title' => $title,
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'manager_id' => $owner->id,
            'total_price' => $total,
            'due_date' => now()->addWeeks(2),
        ]);
    }
}
