<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\Incomes;
use App\Models\CashMovement;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Поступления: витрина над платежами сделок; платёж отсюда попадает в сделку и в кассу. */
class FinanceIncomesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value, 'salary' => 0]));
    }

    public function test_page_lists_payments_with_totals_and_hides_from_the_workshop(): void
    {
        $deal = $this->deal(900_000);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 100_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/1.jpg']);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 250_000, 'method' => 'card', 'paid_at' => now()->subMonths(3), 'receipt_path' => 'r/2.jpg']);

        $all = Livewire::test(Incomes::class)->instance()->totals();
        $this->assertEqualsWithDelta(350_000, $all['total'], 0.01);
        $this->assertEqualsWithDelta(100_000, $all['cash'], 0.01);
        $this->assertEqualsWithDelta(250_000, $all['bank'], 0.01);

        $month = Livewire::test(Incomes::class)->set('month', now()->format('Y-m'))->instance()->totals();
        $this->assertEqualsWithDelta(100_000, $month['total'], 0.01);

        $this->get('/admin/incomes')->assertOk()->assertSee($deal->number);

        $this->actingAs(User::factory()->create(['role' => UserRole::Worker->value]));
        $this->get('/admin/incomes')->assertForbidden();
    }

    public function test_adding_a_receipt_updates_the_deal_and_the_cash_desk(): void
    {
        Storage::fake('public');
        $deal = $this->deal(600_000);

        Livewire::test(Incomes::class)
            ->callAction('addPayment', data: [
                'deal_id' => $deal->id,
                'amount' => 150_000,
                'method' => 'cash',
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertHasNoErrors();

        $deal->refresh();
        $this->assertEqualsWithDelta(150_000, (float) $deal->prepayment, 0.01);
        $this->assertEqualsWithDelta(450_000, $deal->remainingPayment(), 0.01);
        $this->assertSame(1, CashMovement::query()->where('direction', 'in')->count(), 'Платёж не попал в кассу');
        Storage::disk('public')->assertExists(DealPayment::query()->firstOrFail()->receipt_path);
    }

    public function test_overpayment_is_refused_on_the_server(): void
    {
        Storage::fake('public');
        $deal = $this->deal(100_000);

        Livewire::test(Incomes::class)
            ->callAction('addPayment', data: [
                'deal_id' => $deal->id,
                'amount' => 100_001,
                'method' => 'cash',
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertNotified();

        $this->assertSame(0, DealPayment::query()->count());
        $this->assertEqualsWithDelta(0, (float) $deal->refresh()->prepayment, 0.01);
    }

    private function deal(float $total): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Поступления»',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'total_price' => $total,
            'due_date' => now()->addWeeks(2),
        ]);
    }
}
