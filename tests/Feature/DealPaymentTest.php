<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\StageRequirement;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Платежи по сделке: у каждого чек, сумма уходит в предоплату. */
class DealPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->actingAs($this->manager);
    }

    public function test_prepayment_is_the_sum_of_payments(): void
    {
        $deal = $this->makeDeal();

        $this->pay($deal, 50_000);
        $this->pay($deal, 30_000);

        $this->assertEqualsWithDelta(80_000, (float) $deal->refresh()->prepayment, 0.01);
        $this->assertEqualsWithDelta(220_000, $deal->remainingPayment(), 0.01);
    }

    public function test_deleting_a_payment_lowers_the_prepayment(): void
    {
        $deal = $this->makeDeal();
        $this->pay($deal, 50_000);
        $second = $this->pay($deal, 30_000);

        $second->delete();

        $this->assertEqualsWithDelta(50_000, (float) $deal->refresh()->prepayment, 0.01);
    }

    public function test_each_payment_goes_to_history_with_its_author(): void
    {
        $deal = $this->makeDeal();
        $this->pay($deal, 50_000);

        $event = $deal->events()->where('type', DealEventType::Payment->value)->latest('id')->firstOrFail();

        $this->assertStringContainsString('50 000', $event->description);
        $this->assertStringContainsString('чек приложен', $event->description);
        $this->assertSame($this->manager->id, $event->user_id);
        // Поле «Предоплата» меняет автоматика — отдельной правкой в ленте его нет.
        $this->assertSame(0, $deal->events()->where('type', DealEventType::Updated->value)->count());
    }

    public function test_payment_remembers_who_accepted_it(): void
    {
        $payment = $this->pay($this->makeDeal(), 10_000);

        $this->assertSame($this->manager->id, $payment->user_id);
    }

    public function test_receipt_is_uploaded_from_the_deal_card(): void
    {
        Storage::fake('public');
        $deal = $this->makeDeal();

        // fillForm, а не set(): файл должен пройти через FileUpload, иначе
        // Livewire не сможет сериализовать UploadedFile в снимок компонента.
        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->fillForm([
                'payments' => [
                    [
                        'amount' => 70_000,
                        'method' => PaymentMethod::Kaspi->value,
                        'paid_at' => now()->toDateString(),
                        'comment' => 'Первая часть',
                        'receipt_path' => [UploadedFile::fake()->image('check.jpg')],
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $payment = $deal->refresh()->payments()->firstOrFail();

        $this->assertEqualsWithDelta(70_000, (float) $payment->amount, 0.01);
        Storage::disk('public')->assertExists($payment->receipt_path);
        $this->assertEqualsWithDelta(70_000, (float) $deal->prepayment, 0.01);
    }

    public function test_stage_requirement_sees_prepayment_from_payments(): void
    {
        $deal = $this->makeDeal();

        $this->assertFalse(StageRequirement::Prepayment->isSatisfiedBy($deal));

        $this->pay($deal, 10_000);

        $this->assertTrue(StageRequirement::Prepayment->isSatisfiedBy($deal->refresh()));
    }

    private function pay(Deal $deal, int $amount): DealPayment
    {
        return $deal->payments()->create([
            'amount' => $amount,
            'method' => PaymentMethod::Kaspi,
            'paid_at' => now(),
            'receipt_path' => 'receipts/check-'.$amount.'.pdf',
        ]);
    }

    private function makeDeal(): Deal
    {
        return Deal::create([
            'title' => 'Сделка с оплатой',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 300_000,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);
    }
}
