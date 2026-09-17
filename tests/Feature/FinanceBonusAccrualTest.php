<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BonusStatus;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FinanceSettings;
use App\Filament\Pages\MySalary;
use App\Models\Bonus;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\Setting;
use App\Models\User;
use App\Services\BonusAccrual;
use App\Services\DoorProductionService;
use App\Services\PayrollService;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Автобонус менеджеру с закрытой сделки, настройка ставки администратором, «Моя зарплата». */
class FinanceBonusAccrualTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value, 'salary' => 0]);
        $this->actingAs($this->admin);
    }

    public function test_closing_a_deal_accrues_two_percent_to_the_manager_once(): void
    {
        $deal = $this->dealReadyToClose(1_000_000);

        app(DoorProductionService::class)->moveToStage($deal, $this->stage('closed'), $this->admin);

        $this->assertSame(DealStatus::Completed, $deal->refresh()->status_id);

        $bonus = Bonus::query()->where('deal_id', $deal->id)->firstOrFail();
        $this->assertSame($this->manager->id, $bonus->user_id);
        $this->assertEqualsWithDelta(20_000, (float) $bonus->amount, 0.01, '2 % от 1 000 000');
        $this->assertSame(BonusStatus::Pending, $bonus->status);
        $this->assertSame(BonusAccrual::SOURCE_DEAL, $bonus->source);
        $this->assertStringContainsString('2%', $bonus->reason);
        $this->assertSame(1, $this->manager->notifications()->count(), 'Менеджер не узнал о бонусе');

        app(BonusAccrual::class)->forCompletedDeal($deal->refresh(), $this->admin);
        $this->assertSame(1, Bonus::query()->where('deal_id', $deal->id)->count(), 'Бонус начислен дважды');
    }

    public function test_personal_rate_beats_the_global_one_and_zero_disables(): void
    {
        $this->manager->update(['bonus_percent' => 5]);
        $deal = $this->dealReadyToClose(200_000);

        $bonus = app(BonusAccrual::class)->forCompletedDeal($deal, $this->admin);
        $this->assertEqualsWithDelta(10_000, (float) $bonus->amount, 0.01);

        $this->manager->update(['bonus_percent' => 0]);
        $this->assertNull(app(BonusAccrual::class)->forCompletedDeal($this->dealReadyToClose(200_000), $this->admin));
    }

    public function test_admin_changes_the_rate_and_auto_approval_in_settings(): void
    {
        Livewire::test(FinanceSettings::class)
            ->callAction('edit', data: ['percent' => 3.5, 'auto_approve' => true])
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(3.5, Setting::managerBonusPercent(), 0.001);
        $this->assertTrue(Setting::managerBonusAutoApprove());

        $bonus = app(BonusAccrual::class)->forCompletedDeal($this->dealReadyToClose(100_000), $this->admin);
        $this->assertEqualsWithDelta(3_500, (float) $bonus->amount, 0.01);
        $this->assertSame(BonusStatus::Approved, $bonus->status, 'Автоутверждение не сработало');

        $this->actingAs($this->manager);
        $this->get('/admin/finance-settings')->assertForbidden();
    }

    public function test_bonus_lands_in_the_manager_sheet_and_my_salary_shows_only_own_numbers(): void
    {
        $month = now()->format('Y-m');
        Bonus::factory()->approved()->create(['user_id' => $this->manager->id, 'month' => $month, 'amount' => 20_000]);
        $other = User::factory()->create(['role' => UserRole::Worker->value, 'salary' => 150_000, 'hired_at' => now()->subYear()]);
        Bonus::factory()->approved()->create(['user_id' => $other->id, 'month' => $month, 'amount' => 77_777]);

        app(PayrollService::class)->build($month);

        $this->actingAs($this->manager);
        $this->get('/admin/my-salary')->assertOk()->assertSee('20 000')->assertDontSee('77 777')->assertDontSee('150 000');

        $sheet = Livewire::test(MySalary::class)->instance()->sheet();
        $this->assertNotNull($sheet);
        $this->assertEqualsWithDelta(20_000, (float) $sheet->bonuses, 0.01);

        // Цех тоже видит только своё, а общие ведомости и бонусы ему не открываются.
        $this->actingAs($other);
        $this->get('/admin/my-salary')->assertOk()->assertSee('150 000')->assertDontSee('20 000');
        $this->get('/admin/salary')->assertForbidden();
        $this->get('/admin/bonuses')->assertForbidden();
    }

    private function dealReadyToClose(float $total): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Бонус»',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::ReadyToShip,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('delivery')->id,
            'manager_id' => $this->manager->id,
            'total_price' => $total,
            'prepayment' => $total,
            'due_date' => now()->addWeek(),
        ]);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
