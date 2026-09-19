<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\CashAccountType;
use App\Enums\DebtStatus;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Resources\Debts\DebtResource;
use App\Filament\Resources\Debts\Pages\ManageDebts;
use App\Models\CashAccount;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\CashLedger;
use App\Services\FinanceSummary;
use Database\Seeders\CashAccountSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Задолженности: платёж = расход + списание, долг закрывается только по факту. */
class FinanceDebtsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CashAccountSeeder::class]);
        $this->admin = User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]);
        $this->actingAs($this->admin);
    }

    public function test_payment_creates_an_approved_expense_and_writes_off_the_bank(): void
    {
        $debt = Debt::factory()->create(['amount' => 500_000, 'category' => 'rent']);
        $bank = CashAccount::query()->where('type', CashAccountType::Bank->value)->firstOrFail();

        app(CashLedger::class)->payDebt($debt, 200_000, PaymentMethod::Transfer, now(), null, 'expenses/p.pdf', $this->admin);

        $debt->refresh();
        $this->assertEqualsWithDelta(200_000, (float) $debt->paid_amount, 0.01);
        $this->assertEqualsWithDelta(300_000, $debt->remaining(), 0.01);
        $this->assertSame(DebtStatus::Open, $debt->status);

        $expense = Expense::query()->firstOrFail();
        $this->assertTrue($expense->isApproved());
        $this->assertSame('rent', $expense->category->value);
        $this->assertEqualsWithDelta(-200_000, $bank->balance(), 0.01);
        $this->assertEqualsWithDelta(200_000, FinanceSummary::allTime()->approvedExpensesByCategory()['rent'], 0.01);
    }

    public function test_full_payment_closes_the_debt_and_overpayment_is_refused(): void
    {
        $debt = Debt::factory()->create(['amount' => 100_000, 'category' => 'tax']);
        $ledger = app(CashLedger::class);

        try {
            $ledger->payDebt($debt, 100_001, PaymentMethod::Transfer, now(), null, null, $this->admin);
            $this->fail('Переплата прошла');
        } catch (ValidationException) {
        }

        $ledger->payDebt($debt, 100_000, PaymentMethod::Transfer, now(), null, null, $this->admin);

        $this->assertSame(DebtStatus::Paid, $debt->refresh()->status);
        $this->assertFalse($debt->canBeDeleted());

        // Закрытый долг не принимает платежи, даже от администратора (он проходит политику через Gate::before).
        $this->expectException(ValidationException::class);
        $ledger->payDebt($debt, 1, PaymentMethod::Cash, now(), null, null, $this->admin);
    }

    public function test_debt_with_a_remainder_cannot_be_marked_paid_by_hand(): void
    {
        $debt = Debt::factory()->create(['amount' => 50_000]);

        $this->expectException(ValidationException::class);
        $debt->forceFill(['status' => DebtStatus::Paid])->save();
    }

    public function test_accountant_pays_and_other_roles_do_not_see_debts(): void
    {
        $debt = Debt::factory()->create();
        $accountant = User::factory()->create(['role' => UserRole::Accountant->value]);

        $this->assertTrue($accountant->can('create', Debt::class));
        $this->assertTrue($accountant->can('pay', $debt));

        $this->actingAs($accountant);
        $this->get('/admin/debts')->assertOk()->assertSee($debt->counterparty);
        Livewire::test(ManageDebts::class)->assertActionVisible(TestAction::make('pay')->table($debt));

        // Без права «Подтверждение» раздел остаётся, а платить нельзя.
        AccessControl::set(UserRole::Accountant, Permission::FinanceApprove, AccessLevel::None);
        $this->assertFalse($accountant->fresh()->can('pay', $debt->fresh()));
        AccessControl::reset(UserRole::Accountant);

        foreach ([UserRole::Manager, UserRole::Hr, UserRole::Master] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));
            $this->get('/admin/debts')->assertForbidden();
        }
    }

    public function test_overview_shows_open_debts_and_the_overdue_badge(): void
    {
        Debt::factory()->create(['amount' => 300_000, 'due_at' => now()->subDay()->toDateString(), 'counterparty' => 'ТОО «Металл»']);
        Debt::factory()->create(['amount' => 100_000, 'status' => DebtStatus::Cancelled->value]);

        $this->assertEqualsWithDelta(300_000, FinanceSummary::allTime()->debts(), 0.01);
        $this->assertSame('1', DebtResource::getNavigationBadge());
        $this->get('/admin/finance')->assertOk()->assertSee('ТОО «Металл»')->assertSee('Мы должны');
    }

    public function test_pay_action_from_the_table(): void
    {
        Storage::fake('local');
        $debt = Debt::factory()->create(['amount' => 80_000, 'category' => 'loan']);

        Livewire::test(ManageDebts::class)
            ->callAction(TestAction::make('pay')->table($debt), data: [
                'amount' => 80_000,
                'method' => 'cash',
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('loan.jpg')],
            ])
            ->assertHasNoErrors();

        $this->assertSame(DebtStatus::Paid, $debt->refresh()->status);
    }
}
