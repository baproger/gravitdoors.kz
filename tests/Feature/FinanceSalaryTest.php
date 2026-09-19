<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\BonusStatus;
use App\Enums\CashAccountType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\SalarySheetStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SalarySheets;
use App\Filament\Resources\Bonuses\Pages\ManageBonuses;
use App\Models\Bonus;
use App\Models\CashAccount;
use App\Models\Expense;
use App\Models\ProductionLog;
use App\Models\SalarySheet;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\FinanceSummary;
use App\Services\PayrollService;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Бонусы и зарплатная ведомость: оклад + сдельно + бонусы − удержания, утверждение, выплата. */
class FinanceSalaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]);
        $this->payroll = app(PayrollService::class);
        $this->actingAs($this->admin);
    }

    public function test_sheet_sums_salary_piecework_and_approved_bonuses(): void
    {
        $month = now()->format('Y-m');
        $worker = User::factory()->create(['role' => UserRole::Worker->value, 'salary' => 150_000, 'hired_at' => now()->subYear()]);
        ProductionLog::factory()->done()->create(['worker_id' => $worker->id, 'payout' => 12_000, 'finished_at' => now()]);
        Bonus::factory()->approved()->create(['user_id' => $worker->id, 'month' => $month, 'amount' => 20_000]);
        Bonus::factory()->create(['user_id' => $worker->id, 'month' => $month, 'amount' => 999_999]); // не утверждён

        $sheets = $this->payroll->build($month);
        $sheet = $sheets->firstWhere('user_id', $worker->id);

        $this->assertNotNull($sheet);
        $this->assertEqualsWithDelta(150_000, (float) $sheet->salary, 0.01);
        $this->assertEqualsWithDelta(12_000, (float) $sheet->piecework, 0.01);
        $this->assertEqualsWithDelta(20_000, (float) $sheet->bonuses, 0.01);
        $this->assertEqualsWithDelta(182_000, (float) $sheet->total, 0.01);
        $this->assertNull($sheets->firstWhere('user_id', $this->admin->id), 'Пустая ведомость не создаётся');
    }

    public function test_salary_is_prorated_for_a_mid_month_hire(): void
    {
        [$from, $to] = PayrollService::period(now()->format('Y-m'));
        $user = User::factory()->create(['salary' => 300_000, 'hired_at' => $from->addDays(9)]); // с 10-го числа

        $expected = round(300_000 * ($from->daysInMonth - 9) / $from->daysInMonth, 2);
        $this->assertEqualsWithDelta($expected, $this->payroll->proratedSalary($user, $from, $to), 0.01);
        $this->assertEqualsWithDelta(0, $this->payroll->proratedSalary(User::factory()->create(['salary' => 1, 'hired_at' => $to->addDay()]), $from, $to), 0.01);
    }

    public function test_deductions_change_only_drafts_and_approval_freezes_the_sheet(): void
    {
        $month = now()->format('Y-m');
        $user = User::factory()->create(['salary' => 200_000, 'hired_at' => now()->subYear()]);
        $sheet = $this->payroll->build($month)->firstWhere('user_id', $user->id);

        $this->payroll->adjust($sheet, 10_000, 50_000, 'аванс 5-го');
        $this->assertEqualsWithDelta(140_000, (float) $sheet->refresh()->total, 0.01);

        $this->payroll->approve($sheet, $this->admin);
        $this->assertSame(SalarySheetStatus::Approved, $sheet->refresh()->status);

        // Повышение оклада после утверждения месяц не меняет.
        $user->update(['salary' => 999_000]);
        $this->payroll->build($month);
        $this->assertEqualsWithDelta(140_000, (float) $sheet->refresh()->total, 0.01);

        $this->expectException(ValidationException::class);
        $this->payroll->adjust($sheet, 0, 0, null);
    }

    public function test_payment_creates_a_salary_expense_and_closes_the_sheet(): void
    {
        $month = now()->format('Y-m');
        $user = User::factory()->create(['salary' => 100_000, 'hired_at' => now()->subYear()]);
        $sheet = $this->payroll->build($month)->firstWhere('user_id', $user->id);
        $cash = CashAccount::query()->where('type', CashAccountType::Cash->value)->firstOrFail();

        try {
            $this->payroll->pay($sheet, 100_000, PaymentMethod::Cash, now(), null, $this->admin);
            $this->fail('Черновик выплатился');
        } catch (ValidationException) {
        }

        $this->payroll->approve($sheet, $this->admin);
        $this->payroll->pay($sheet, 40_000, PaymentMethod::Cash, now(), null, $this->admin);

        $sheet->refresh();
        $this->assertSame(SalarySheetStatus::Approved, $sheet->status);
        $this->assertEqualsWithDelta(60_000, $sheet->remaining(), 0.01);

        try {
            $this->payroll->pay($sheet, 60_001, PaymentMethod::Cash, now(), null, $this->admin);
            $this->fail('Переплата прошла');
        } catch (ValidationException) {
        }

        $this->payroll->pay($sheet, 60_000, PaymentMethod::Cash, now(), null, $this->admin);

        $this->assertSame(SalarySheetStatus::Paid, $sheet->refresh()->status);
        $this->assertEqualsWithDelta(-100_000, $cash->balance(), 0.01, 'Выплата не списалась из кассы');
        $this->assertSame(2, Expense::query()->where('category', ExpenseCategory::Salary->value)->approved()->count());
    }

    public function test_overview_uses_the_approved_sheet_and_does_not_double_count_salary_expenses(): void
    {
        $month = now()->format('Y-m');
        $user = User::factory()->create(['salary' => 100_000, 'hired_at' => now()->subYear()]);
        $sheet = $this->payroll->build($month)->firstWhere('user_id', $user->id);

        $estimate = FinanceSummary::month($month);
        $this->assertFalse($estimate->hasPayrollSheets(), 'Черновик — ещё не ведомость');

        $this->payroll->approve($sheet, $this->admin);
        $this->payroll->pay($sheet, 100_000, PaymentMethod::Cash, now(), null, $this->admin);

        $summary = FinanceSummary::month($month);
        $labels = array_column($summary->expenseLines(), 'label');

        $this->assertTrue($summary->hasPayrollSheets());
        $this->assertContains('Зарплата по ведомости', $labels);
        $this->assertNotContains('Зарплата', $labels, 'Выплата зарплаты посчитана дважды');
        $this->assertEqualsWithDelta(100_000, $summary->expenses(), 0.01);
    }

    public function test_bonus_workflow_and_access(): void
    {
        $hr = User::factory()->create(['role' => UserRole::Hr->value]);
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        $this->actingAs($hr);

        Livewire::test(ManageBonuses::class)
            ->callAction('create', data: ['user_id' => $worker->id, 'month' => now()->format('Y-m'), 'amount' => 15_000, 'reason' => 'Сдал заказ раньше срока'])
            ->assertHasNoErrors();

        $bonus = Bonus::query()->firstOrFail();
        $this->assertSame(BonusStatus::Pending, $bonus->status);
        $this->assertSame($hr->id, $bonus->created_by);

        // Менеджеру продаж бонусы не положены вовсе.
        $this->assertFalse($manager->can('approve', $bonus));
        $this->assertFalse($manager->can('create', Bonus::class));

        // Кадры утверждают; без права «Подтверждение» кнопка пропадает.
        AccessControl::set(UserRole::Hr, Permission::FinanceApprove, AccessLevel::None);
        Livewire::test(ManageBonuses::class)->assertActionHidden(TestAction::make('approve')->table($bonus));
        AccessControl::reset(UserRole::Hr);

        Livewire::test(ManageBonuses::class)->callAction(TestAction::make('approve')->table($bonus))->assertHasNoErrors();

        $bonus->refresh();
        $this->assertSame(BonusStatus::Approved, $bonus->status);
        $this->assertFalse($bonus->canBeDeleted());
        $this->assertFalse($bonus->delete());

        $this->actingAs($worker);
        $this->get('/admin/bonuses')->assertForbidden();
        $this->get('/admin/salary')->assertForbidden();
    }

    public function test_salary_page_builds_and_approves_from_the_ui(): void
    {
        User::factory()->create(['salary' => 120_000, 'hired_at' => now()->subYear()]);

        Livewire::test(SalarySheets::class)
            ->callAction('build')
            ->assertHasNoErrors()
            ->callAction('approveAll')
            ->assertHasNoErrors();

        $this->assertSame(1, SalarySheet::query()->where('status', SalarySheetStatus::Approved->value)->count());
        $this->get('/admin/salary')->assertOk()->assertSee('120 000');
    }
}
