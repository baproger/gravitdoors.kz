<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\FinanceOverview;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\FinanceSummary;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Финансы — обзор: цифры сходятся с данными сделок, платежей, цеха и склада. */
class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value, 'salary' => 0]));
    }

    public function test_page_opens_for_office_and_is_hidden_from_the_workshop(): void
    {
        $this->get('/admin/finance')->assertOk()->assertSee('Сумма договоров');

        $this->actingAs(User::factory()->create(['role' => UserRole::Master->value]));
        $this->get('/admin/finance')->assertForbidden();
    }

    public function test_summary_adds_up(): void
    {
        $paid = $this->deal(1_000_000, 'ЖК «А»');
        $this->deal(500_000, 'ЖК «Б»');
        $cancelled = $this->deal(300_000, 'ЖК «В»');
        $cancelled->forceFill(['status_id' => DealStatus::Cancelled])->saveQuietly();

        DealPayment::create(['deal_id' => $paid->id, 'amount' => 400_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/1.jpg']);
        DealPayment::create(['deal_id' => $paid->id, 'amount' => 100_000, 'method' => 'kaspi', 'paid_at' => now(), 'receipt_path' => 'r/2.jpg']);

        $worker = User::factory()->create(['role' => UserRole::Worker->value, 'salary' => 0]);
        ProductionLog::factory()->done()->create(['worker_id' => $worker->id, 'payout' => 7_500, 'finished_at' => now()]);

        $material = MaterialStock::query()->firstOrFail();
        StockMovement::create(['material_stock_id' => $material->id, 'type' => 'in', 'quantity' => 10, 'price_per_unit' => 1_000, 'comment' => 'закуп']);

        $s = FinanceSummary::allTime();

        $this->assertEqualsWithDelta(1_500_000, $s->contracts(), 0.01, 'Отменённая сделка не в сумме договоров');
        $this->assertEqualsWithDelta(1_000_000, $s->receivables(), 0.01, '1 000 000 − 500 000 оплачено + 500 000 без оплаты');
        $this->assertEqualsWithDelta(500_000, $s->receipts(), 0.01);
        $this->assertEqualsWithDelta(400_000, $s->receiptsCash(), 0.01);
        $this->assertEqualsWithDelta(100_000, $s->receiptsBank(), 0.01);
        $this->assertEqualsWithDelta(7_500, $s->piecework(), 0.01);
        $this->assertEqualsWithDelta(10_000, $s->purchases(), 0.01);
        $this->assertEqualsWithDelta(500_000 - 17_500, $s->net(), 0.01);
    }

    public function test_month_filter_narrows_receipts_and_piecework(): void
    {
        $deal = $this->deal(900_000, 'ЖК «Месяц»');
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 100_000, 'method' => 'cash', 'paid_at' => now()->subMonths(2), 'receipt_path' => 'r/3.jpg']);
        DealPayment::create(['deal_id' => $deal->id, 'amount' => 50_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/4.jpg']);

        $this->assertEqualsWithDelta(50_000, FinanceSummary::month(now()->format('Y-m'))->receipts(), 0.01);
        $this->assertEqualsWithDelta(150_000, FinanceSummary::allTime()->receipts(), 0.01);

        Livewire::test(FinanceOverview::class)
            ->set('month', now()->format('Y-m'))
            ->assertSee('50 000');
    }

    public function test_returns_to_stock_are_not_purchases(): void
    {
        $deal = $this->deal(100_000, 'ЖК «Возврат»');
        $material = MaterialStock::query()->firstOrFail();
        StockMovement::create(['material_stock_id' => $material->id, 'deal_id' => $deal->id, 'type' => 'in', 'quantity' => 5, 'price_per_unit' => 1_000, 'comment' => 'возврат']);

        $this->assertEqualsWithDelta(0, FinanceSummary::allTime()->purchases(), 0.01);
    }

    private function deal(float $total, string $title): Deal
    {
        return Deal::create([
            'title' => $title,
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'total_price' => $total,
            'due_date' => now()->addWeeks(2),
        ]);
    }
}
