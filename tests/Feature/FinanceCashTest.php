<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CashAccountType;
use App\Enums\DealStatus;
use App\Enums\ExpenseStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\CashDesk;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Expense;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\CashLedger;
use App\Services\FinanceSummary;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Касса и банк: движения приходят из платежей и расходов, остаток считается по журналу. */
class FinanceCashTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $cash;

    private CashAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);
        $this->cash = CashAccount::query()->where('type', CashAccountType::Cash->value)->firstOrFail();
        $this->bank = CashAccount::query()->where('type', CashAccountType::Bank->value)->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]));
    }

    public function test_payment_lands_in_cash_or_bank_by_method_and_follows_edits(): void
    {
        $deal = $this->deal(1_000_000);
        $payment = DealPayment::create(['deal_id' => $deal->id, 'amount' => 200_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/1.jpg']);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 300_000, 'method' => 'kaspi', 'paid_at' => now(), 'receipt_path' => 'r/2.jpg']);

        $this->assertEqualsWithDelta(200_000, $this->cash->balance(), 0.01);
        $this->assertEqualsWithDelta(300_000, $this->bank->balance(), 0.01);

        $payment->update(['amount' => 250_000]);
        $this->assertEqualsWithDelta(250_000, $this->cash->balance(), 0.01, 'Правка платежа не обновила движение');
        $this->assertSame(1, CashMovement::query()->where('source_id', $payment->id)->count(), 'Движение задвоилось');

        $payment->delete();
        $this->assertEqualsWithDelta(0, $this->cash->balance(), 0.01, 'Удалённый платёж остался в кассе');
    }

    public function test_only_approved_expense_is_written_off_and_rejection_returns_it(): void
    {
        $expense = Expense::factory()->create(['method' => 'transfer', 'amount' => 120_000]);
        $this->assertEqualsWithDelta(0, $this->bank->balance(), 0.01, 'Неподтверждённый расход списался');

        $expense->forceFill(['status' => ExpenseStatus::Approved])->save();
        $this->assertEqualsWithDelta(-120_000, $this->bank->balance(), 0.01);

        $expense->forceFill(['status' => ExpenseStatus::Rejected, 'rejection_reason' => 'Дубль'])->save();
        $this->assertEqualsWithDelta(0, $this->bank->balance(), 0.01, 'Отклонённый расход не вернулся');
    }

    public function test_transfer_moves_money_between_accounts_without_changing_the_total(): void
    {
        $this->cash->update(['opening_balance' => 500_000]);
        $ledger = app(CashLedger::class);

        $ledger->transfer($this->cash->refresh(), $this->bank, 200_000, now(), 'Инкассация', auth()->user());

        $this->assertEqualsWithDelta(300_000, $this->cash->balance(), 0.01);
        $this->assertEqualsWithDelta(200_000, $this->bank->balance(), 0.01);
        $this->assertSame(2, CashMovement::query()->whereNotNull('transfer_id')->count());
    }

    public function test_transfer_to_the_same_account_and_zero_adjustment_are_rejected(): void
    {
        $ledger = app(CashLedger::class);

        try {
            $ledger->transfer($this->cash, $this->cash, 1_000, now(), null, null);
            $this->fail('Перевод на тот же счёт прошёл');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $ledger->adjust($this->cash, 0, now(), 'пусто', null);
    }

    public function test_adjustment_is_admin_only_and_keeps_its_reason(): void
    {
        Livewire::test(CashDesk::class)
            ->callAction('adjust', data: ['account' => $this->cash->id, 'amount' => -5_000, 'at' => now()->toDateString(), 'reason' => 'Недостача при пересчёте'])
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(-5_000, $this->cash->balance(), 0.01);
        $this->assertStringContainsString('Недостача', CashMovement::query()->firstOrFail()->comment);

        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));
        Livewire::test(CashDesk::class)->assertActionHidden('adjust');
    }

    public function test_backfill_covers_old_records_once(): void
    {
        $deal = $this->deal(500_000);
        $payment = DealPayment::create(['deal_id' => $deal->id, 'amount' => 100_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/3.jpg']);
        $payment->cashMovement()->delete(); // как будто платёж был до появления кассы
        Expense::factory()->approved()->create(['method' => 'cash', 'amount' => 30_000])->cashMovement()->delete();

        $this->assertEqualsWithDelta(0, $this->cash->balance(), 0.01);

        $this->artisan('gravit:finance-backfill')->assertSuccessful();
        $this->artisan('gravit:finance-backfill')->assertSuccessful();

        $this->assertEqualsWithDelta(70_000, $this->cash->balance(), 0.01, 'Backfill задвоил или пропустил');
    }

    public function test_overview_shows_account_balances_and_the_page_opens_for_the_office_only(): void
    {
        $deal = $this->deal(500_000);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 100_000, 'method' => 'card', 'paid_at' => now(), 'receipt_path' => 'r/4.jpg']);

        $summary = FinanceSummary::allTime();
        $this->assertEqualsWithDelta(100_000, $summary->bankBalance(), 0.01);
        $this->assertEqualsWithDelta(0, $summary->cashBalance(), 0.01);

        $this->get('/admin/cash')->assertOk()->assertSee('Оплата по сделке');

        $this->actingAs(User::factory()->create(['role' => UserRole::Master->value]));
        $this->get('/admin/cash')->assertForbidden();
    }

    private function deal(float $total): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Касса»',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'total_price' => $total,
            'due_date' => now()->addWeeks(2),
        ]);
    }
}
