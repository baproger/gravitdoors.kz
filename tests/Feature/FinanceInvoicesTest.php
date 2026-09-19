<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\Invoices;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Счета: вкладки по оплате, приём оплаты и напоминание из списка. */
class FinanceInvoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);
        // Счета — раздел финансов: менеджер видит остаток в своей карточке сделки.
        $this->actingAs(User::factory()->create(['role' => UserRole::Accountant->value, 'salary' => 0]));
    }

    public function test_tabs_split_deals_by_payment_state(): void
    {
        $awaiting = $this->deal(500_000);
        $overdue = $this->deal(300_000, now()->subDays(3));
        $paid = $this->deal(200_000);
        DealPayment::create(['deal_id' => $paid->id, 'amount' => 200_000, 'method' => 'cash', 'paid_at' => now(), 'receipt_path' => 'r/1.jpg']);
        $cancelled = $this->deal(999_000);
        $cancelled->forceFill(['status_id' => DealStatus::Cancelled])->saveQuietly();

        $modes = Livewire::test(Invoices::class)->instance()->modes();

        $this->assertSame(2, $modes['awaiting']['count']);
        $this->assertEqualsWithDelta(800_000, $modes['awaiting']['sum'], 0.01);
        $this->assertSame(1, $modes['overdue']['count']);
        $this->assertSame(1, $modes['paid']['count']);
        $this->assertSame('1', Invoices::getNavigationBadge());

        $this->get('/admin/invoices?mode=overdue')->assertOk()->assertSee($overdue->number)->assertDontSee($awaiting->number);

        foreach ([UserRole::Master, UserRole::Manager, UserRole::Hr] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));
            $this->get('/admin/invoices')->assertForbidden();
        }
    }

    public function test_payment_from_the_list_and_reminder_in_history(): void
    {
        Storage::fake('local');
        $deal = $this->deal(400_000);

        Livewire::test(Invoices::class)
            ->callAction(TestAction::make('pay')->table($deal), data: [
                'amount' => 400_000,
                'method' => 'card',
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertHasNoErrors();

        $this->assertTrue($deal->refresh()->isPaidInFull());

        $other = $this->deal(100_000);

        Livewire::test(Invoices::class)
            ->callAction(TestAction::make('remind')->table($other))
            ->assertHasNoErrors();

        // Оплаченная сделка ушла из вкладки «Ожидают оплату» и видна в «Оплачены».
        Livewire::test(Invoices::class)->set('mode', 'paid')
            ->assertActionHidden(TestAction::make('pay')->table($deal))
            ->assertActionHidden(TestAction::make('remind')->table($deal));

        $this->assertTrue($other->events()->where('type', 'payment_reminder')->exists());
    }

    private function deal(float $total, ?\DateTimeInterface $due = null): Deal
    {
        return Deal::create([
            'title' => 'ЖК «Счета»',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'total_price' => $total,
            'due_date' => $due ?? now()->addWeeks(2),
        ]);
    }
}
