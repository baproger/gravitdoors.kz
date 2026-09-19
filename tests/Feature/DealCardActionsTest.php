<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Actions\DealActions;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Кнопки внутри карточки сделки, сгруппированные по отделам.
 *
 * Главная — «Принять оплату»: подставляет остаток, сохраняет платёж сразу и
 * открывает дорогу на «Сделка закрыта». Раньше платёж прятался в репитере
 * вкладки «Оплата», и менеджер не понимал, чего не хватает для закрытия.
 */
class DealCardActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed([FactoryStageSeeder::class, CashAccountSeeder::class]);

        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->accountant = User::factory()->create(['role' => UserRole::Accountant->value]);
    }

    public function test_pay_button_defaults_to_the_remaining_balance(): void
    {
        $deal = $this->deal(300_000);
        $deal->payments()->create(['amount' => 100_000, 'method' => PaymentMethod::Kaspi, 'paid_at' => now(), 'receipt_path' => 'receipts/1.pdf']);

        $component = Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->mountAction('pay')
            ->assertActionMounted('pay');

        $this->assertEqualsWithDelta(200_000, (float) $component->instance()->mountedActions[0]['data']['amount'], 0.01);
    }

    public function test_first_payment_defaults_to_the_whole_contract_sum(): void
    {
        $deal = $this->deal(300_000);

        $component = Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->mountAction('pay');

        $this->assertEqualsWithDelta(300_000, (float) $component->instance()->mountedActions[0]['data']['amount'], 0.01);
    }

    public function test_paying_the_balance_from_the_card_unlocks_the_final_stage(): void
    {
        $deal = $this->deal(300_000);

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->callAction('pay', data: [
                'amount' => 300_000,
                'method' => PaymentMethod::Card->value,
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertHasNoErrors()
            ->assertNotified('Сделка оплачена полностью');

        $deal->refresh();
        $this->assertTrue($deal->isPaidInFull());
        $this->assertEqualsWithDelta(300_000, (float) $deal->prepayment, 0.01);
        $this->assertSame($this->manager->id, $deal->payments()->first()->user_id);

        // Кнопка исчезает: остатка нет.
        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->assertActionHidden('pay');
    }

    public function test_partial_payment_keeps_the_button_and_reports_the_rest(): void
    {
        $deal = $this->deal(300_000);

        // У бухгалтера воронка продаж «только чтение», поэтому карточка открывается
        // страницей просмотра — кнопка «Принять оплату» есть и там.
        Livewire::actingAs($this->accountant)
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('pay', data: [
                'amount' => 120_000,
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertHasNoErrors()
            ->assertNotified('Оплата принята')
            ->assertActionVisible('pay');

        $this->assertEqualsWithDelta(180_000, $deal->refresh()->remainingPayment(), 0.01);
    }

    public function test_payment_above_the_balance_is_rejected(): void
    {
        $deal = $this->deal(300_000);

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->callAction('pay', data: [
                'amount' => 400_000,
                'method' => PaymentMethod::Card->value,
                'paid_at' => now()->toDateString(),
                'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
            ])
            ->assertHasActionErrors(['amount']);

        $this->assertSame(0, $deal->payments()->count());
    }

    public function test_payment_without_a_receipt_is_rejected(): void
    {
        $deal = $this->deal(300_000);

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->callAction('pay', data: [
                'amount' => 300_000,
                'method' => PaymentMethod::Card->value,
                'paid_at' => now()->toDateString(),
            ])
            ->assertHasActionErrors(['receipt_path']);

        $this->assertSame(0, $deal->payments()->count());
    }

    public function test_stepper_offers_the_pay_button_when_only_payment_is_missing(): void
    {
        $deal = $this->deal(300_000);
        $final = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('is_final', true)->firstOrFail();
        $beforeFinal = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('order', '<', $final->order)->orderByDesc('order')->firstOrFail();
        $deal->forceFill(['current_stage_id' => $beforeFinal->id, 'status_id' => DealStatus::ReadyToShip])->save();

        $this->actingAs($this->manager)
            ->get("/admin/deals/{$deal->id}/edit")
            ->assertOk()
            ->assertSee('кнопка «Принять оплату»')
            ->assertSee("mountAction('pay')", false);
    }

    public function test_worker_gets_no_pay_button(): void
    {
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);
        $deal = $this->deal(300_000);
        $deal->doorConfigurations()->create(['quantity' => 1, 'height_mm' => 2050, 'width_mm' => 950, 'calculated_price' => 300_000]);
        $order = app(DoorProductionService::class)->handOffToProduction($deal->refresh(), $this->manager);

        $this->actingAs($worker);
        $this->assertFalse(DealActions::canAcceptPayment($deal));

        // Рабочий видит только наряды; на наряде оплаты нет вовсе.
        $this->get("/admin/deals/{$order->id}")
            ->assertOk()
            ->assertDontSee("mountAction('pay')", false)
            ->assertDontSee('Принять оплату');
    }

    public function test_manager_cannot_take_payment_on_someone_elses_deal(): void
    {
        $other = User::factory()->create(['role' => UserRole::Manager->value]);
        $deal = $this->deal(300_000, manager: $other);

        // «Только свои»: чужая сделка вообще не открывается.
        $this->actingAs($this->manager)->get("/admin/deals/{$deal->id}/edit")->assertNotFound();
    }

    public function test_header_shows_department_groups_by_role(): void
    {
        $deal = $this->deal(300_000);

        $this->actingAs($this->accountant)
            ->get("/admin/deals/{$deal->id}")
            ->assertOk()
            ->assertSee('Финансы')
            ->assertSee('Заблокировать отгрузку')
            ->assertSee('Принять оплату')
            ->assertDontSee('Факт материалов');

        $this->actingAs($this->manager)
            ->get("/admin/deals/{$deal->id}/edit")
            ->assertOk()
            ->assertSee('Отдел продаж')
            ->assertSee('Передать в производство')
            ->assertDontSee('Заблокировать отгрузку');
    }

    public function test_hand_off_from_the_card_creates_the_order_and_refreshes_the_card(): void
    {
        $deal = $this->deal(300_000);
        $handOff = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('triggers_production', true)->firstOrFail();
        $before = FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('order', '<', $handOff->order)->orderByDesc('order')->firstOrFail();
        $deal->forceFill(['current_stage_id' => $before->id])->save();

        // Без спецификации и договора регламент не пустит — заполняем минимум.
        $deal->doorConfigurations()->create(['quantity' => 1, 'height_mm' => 2050, 'width_mm' => 950, 'calculated_price' => 300_000]);
        $deal->forceFill(['contract_number' => '17', 'contract_date' => now(), 'documents' => ['contracts/17.pdf'], 'measured_at' => now()->subDay(), 'client_address' => 'ул. Абая, 1', 'city' => 'Алматы', 'manager_id' => $this->manager->id])->save();
        $deal->payments()->create(['amount' => 100_000, 'method' => PaymentMethod::Kaspi, 'paid_at' => now(), 'receipt_path' => 'receipts/1.pdf']);

        $missing = $handOff->missingFor($deal->refresh());
        $this->assertSame([], $missing, 'регламент передачи: '.collect($missing)->map(fn ($r) => $r->getLabel())->implode(', '));

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->callAction('handOff')
            ->assertHasNoErrors()
            ->assertNotified('Наряд создан')
            ->assertActionHidden('handOff');

        $this->assertNotNull($deal->refresh()->activeProductionOrder());
    }

    public function test_factory_group_on_an_order_closes_the_stage(): void
    {
        $master = User::factory()->create(['role' => UserRole::Master->value]);
        $deal = $this->deal(300_000);
        $deal->doorConfigurations()->create(['quantity' => 1, 'height_mm' => 2050, 'width_mm' => 950, 'calculated_price' => 300_000]);
        $order = app(DoorProductionService::class)->handOffToProduction($deal->refresh(), $master);

        // У начальника производства «Сделки и наряды» — чтение: наряд открывается
        // просмотром, но группа «Завод» с кнопками там есть, права у него полные.
        $this->actingAs($master)
            ->get("/admin/deals/{$order->id}")
            ->assertOk()
            ->assertSee('Завод')
            ->assertSee('Завершить этап цеха')
            ->assertDontSee("mountAction('pay')", false);

        $first = $order->currentStage->code;

        Livewire::actingAs($master)
            ->test(ViewDeal::class, ['record' => $order->id])
            ->callAction('completeStage')
            ->assertHasNoErrors();

        $this->assertNotSame($first, $order->refresh()->currentStage->code);
    }

    private function deal(float $total, ?User $manager = null): Deal
    {
        return Deal::create([
            'title' => 'Сделка с кнопками',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => $total,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => ($manager ?? $this->manager)->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);
    }
}
