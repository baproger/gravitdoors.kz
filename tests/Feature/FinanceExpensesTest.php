<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Models\Expense;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\FinanceSummary;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Расходы: менеджер вносит, администратор подтверждает, в сводку идут только подтверждённые. */
class FinanceExpensesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value, 'salary' => 0]);
        $this->accountant = User::factory()->create(['role' => UserRole::Accountant->value, 'salary' => 0]);
        $this->actingAs($this->admin);
    }

    public function test_only_finance_roles_see_expenses(): void
    {
        $this->actingAs($this->accountant);
        $this->get('/admin/expenses')->assertOk();

        // Менеджеру продаж и цеху расходы компании не положены.
        foreach ([$this->manager, User::factory()->create(['role' => UserRole::Master->value])] as $stranger) {
            $this->actingAs($stranger);
            $this->get('/admin/expenses')->assertForbidden();
        }
    }

    public function test_accountant_creates_a_pending_expense_from_the_page(): void
    {
        Storage::fake('public');
        $this->actingAs($this->accountant);

        Livewire::test(ManageExpenses::class)
            ->callAction('create', data: [
                'category' => ExpenseCategory::Rent->value,
                'amount' => 350_000,
                'spent_at' => now()->toDateString(),
                'method' => 'transfer',
                'counterparty' => 'ТОО «Арендодатель»',
                'receipt_path' => [UploadedFile::fake()->image('rent.jpg')],
            ])
            ->assertHasNoErrors();

        $expense = Expense::query()->firstOrFail();

        Storage::disk('public')->assertExists($expense->receipt_path);

        $this->assertSame(ExpenseStatus::Pending, $expense->status);
        $this->assertSame($this->accountant->id, $expense->user_id);
        $this->assertEqualsWithDelta(0, FinanceSummary::allTime()->approvedExpensesByCategory()['rent'] ?? 0, 0.01, 'Неподтверждённый расход не в сводке');
    }

    public function test_approval_needs_the_separate_right(): void
    {
        $expense = Expense::factory()->create(['user_id' => $this->accountant->id]);

        $this->assertFalse($this->manager->can('approve', $expense));
        $this->assertTrue($this->accountant->can('approve', $expense));

        // Сняв «Подтверждение», директор оставляет проверку за собой.
        AccessControl::set(UserRole::Accountant, Permission::FinanceApprove, AccessLevel::None);
        $this->assertFalse($this->accountant->fresh()->can('approve', $expense));
        AccessControl::reset(UserRole::Accountant);

        Livewire::test(ManageExpenses::class)
            ->callAction(TestAction::make('approve')->table($expense))
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Approved, $expense->status);
        $this->assertSame($this->admin->id, $expense->approved_by);
        $this->assertNotNull($expense->approved_at);
    }

    public function test_receipt_is_required_to_approve_except_salary_and_tax(): void
    {
        $noReceipt = Expense::factory()->create(['receipt_path' => null, 'category' => ExpenseCategory::Rent->value]);

        try {
            $noReceipt->forceFill(['status' => ExpenseStatus::Approved])->save();
            $this->fail('Аренда без чека подтвердилась');
        } catch (ValidationException) {
        }

        $this->assertSame(ExpenseStatus::Pending, $noReceipt->refresh()->status);

        $tax = Expense::factory()->create(['receipt_path' => null, 'category' => ExpenseCategory::Tax->value]);
        $tax->forceFill(['status' => ExpenseStatus::Approved])->save();

        $this->assertSame(ExpenseStatus::Approved, $tax->refresh()->status);
    }

    public function test_amount_and_date_are_validated_on_the_server(): void
    {
        $this->expectException(ValidationException::class);
        Expense::factory()->create(['amount' => 0]);
    }

    public function test_future_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        Expense::factory()->create(['spent_at' => now()->addDay()->toDateString()]);
    }

    public function test_approved_expense_is_not_deleted_only_rejected(): void
    {
        $expense = Expense::factory()->approved()->create();

        $this->assertFalse($expense->canBeDeleted());
        $this->assertFalse($expense->delete());
        $this->assertNull($expense->refresh()->deleted_at);

        Livewire::test(ManageExpenses::class)
            ->assertActionHidden(TestAction::make('delete')->table($expense))
            ->callAction(TestAction::make('reject')->table($expense), data: ['reason' => 'Дубль'])
            ->assertHasNoErrors();

        $expense->refresh();
        $this->assertSame(ExpenseStatus::Rejected, $expense->status);
        $this->assertSame('Дубль', $expense->rejection_reason);
        $this->assertNull($expense->approved_at);
        $this->assertTrue($expense->canBeDeleted());
    }

    public function test_approved_expense_is_not_editable_and_strangers_edit_nothing(): void
    {
        $pending = Expense::factory()->create(['user_id' => $this->accountant->id]);
        $approved = Expense::factory()->approved()->create(['user_id' => $this->accountant->id]);

        $this->assertTrue($this->accountant->can('update', $pending));
        $this->assertFalse($this->accountant->can('update', $approved), 'Подтверждённый расход правят только отклонением');
        $this->assertFalse($this->manager->can('update', $pending));
    }

    public function test_approved_expenses_land_in_the_overview_by_category_and_period(): void
    {
        Expense::factory()->approved()->create(['category' => 'rent', 'amount' => 300_000, 'spent_at' => now()->toDateString()]);
        Expense::factory()->approved()->create(['category' => 'rent', 'amount' => 300_000, 'spent_at' => now()->subMonths(2)->toDateString()]);
        Expense::factory()->approved()->create(['category' => 'tax', 'amount' => 50_000, 'receipt_path' => null, 'spent_at' => now()->toDateString()]);
        Expense::factory()->create(['category' => 'marketing', 'amount' => 999_999]);

        $month = FinanceSummary::month(now()->format('Y-m'));
        $byCategory = $month->approvedExpensesByCategory();

        $this->assertEqualsWithDelta(300_000, $byCategory['rent'], 0.01);
        $this->assertEqualsWithDelta(50_000, $byCategory['tax'], 0.01);
        $this->assertArrayNotHasKey('marketing', $byCategory);
        $this->assertEqualsWithDelta(350_000, $month->expenses(), 0.01);
        $this->assertEqualsWithDelta(650_000, FinanceSummary::allTime()->expenses(), 0.01);

        $this->get('/admin/finance')->assertOk()->assertSee('Аренда')->assertSee('Налоги');
    }

    public function test_bulk_approve_skips_expenses_without_a_receipt(): void
    {
        $ok = Expense::factory()->create();
        $noReceipt = Expense::factory()->create(['receipt_path' => null, 'category' => 'rent']);

        Livewire::test(ManageExpenses::class)
            ->selectTableRecords([$ok->id, $noReceipt->id])
            ->callAction(TestAction::make('approveSelected')->table()->bulk())
            ->assertHasNoErrors();

        $this->assertSame(ExpenseStatus::Approved, $ok->refresh()->status);
        $this->assertSame(ExpenseStatus::Pending, $noReceipt->refresh()->status);
    }
}
