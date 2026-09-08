<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Карточка сделки: полоса этапов, данные клиента, суммы с услугами. */
class DealCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_stage_strip_moves_the_deal_and_triggers_automation(): void
    {
        $deal = $this->makeDeal();
        $target = $this->stage('handed_to_production');

        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->call('moveToStage', $target->id)
            ->assertHasNoErrors();

        $deal->refresh();

        $this->assertSame($target->id, $deal->current_stage_id);
        $this->assertSame(DealStatus::HandedToProduction, $deal->status_id);
        $this->assertNotNull($deal->productionOrder, 'Полоса этапов не запустила автоматику');
    }

    /** Цех до карточки сделки продаж не доходит вовсе: запрос ресурса её не отдаёт. */
    public function test_workshop_staff_cannot_open_a_sales_deal_card(): void
    {
        $deal = $this->makeDeal();
        $worker = User::factory()->create(['role' => UserRole::Worker->value]);

        $this->actingAs($worker);

        $this->get("/admin/deals/{$deal->id}/edit")->assertNotFound();
        $this->assertTrue($worker->cannot('move', $deal), 'Политика должна запрещать движение сделки продаж');
    }

    public function test_card_holds_full_client_details(): void
    {
        $deal = $this->makeDeal([
            'client_type' => ClientType::Company,
            'client_company' => 'ТОО «Тест-Строй»',
            'client_bin' => '123456789012',
            'client_email' => 'test@example.kz',
            'client_phone_extra' => '+7 700 111 22 33',
            'city' => 'Алматы',
        ]);

        // Значения полей живут в снимке Livewire с экранированием юникода,
        // поэтому проверяем состояние формы, а не разметку.
        Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->assertFormSet([
                // В состоянии формы лежит enum из каста модели, а не строка.
                'client_type' => ClientType::Company,
                'client_company' => 'ТОО «Тест-Строй»',
                'client_bin' => '123456789012',
                'client_email' => 'test@example.kz',
                'client_phone_extra' => '+7 700 111 22 33',
                'city' => 'Алматы',
            ]);

        $this->get("/admin/deals/{$deal->id}/edit")
            ->assertOk()
            ->assertSee('Название компании')
            ->assertSee('БИН / ИИН');
    }

    public function test_company_is_shown_instead_of_contact_person_in_lists(): void
    {
        $deal = $this->makeDeal([
            'client_name' => 'Ержан Тулегенов',
            'client_type' => ClientType::Company,
            'client_company' => 'ТОО «Тест-Строй»',
        ]);

        $this->assertSame('ТОО «Тест-Строй»', $deal->clientTitle());

        $person = $this->makeDeal(['client_name' => 'Марина Ким', 'title' => 'Частный дом']);

        $this->assertSame('Марина Ким', $person->clientTitle());
    }

    public function test_delivery_and_installation_are_added_to_the_deal_total(): void
    {
        $deal = $this->makeDeal(['delivery_cost' => 15_000, 'installation_cost' => 25_000]);

        app(DoorProductionService::class)->syncPricing($deal);

        $doors = (float) $deal->refresh()->doorConfigurations()->sum('calculated_price');

        $this->assertEqualsWithDelta($doors + 40_000, (float) $deal->total_price, 0.01);
    }

    public function test_remaining_payment_accounts_for_the_prepayment(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['total_price' => 300_000, 'prepayment' => 120_000]);

        $this->assertEqualsWithDelta(180_000, $deal->remainingPayment(), 0.01);
        $this->assertFalse($deal->isPaidInFull());

        $deal->update(['prepayment' => 300_000]);

        $this->assertSame(0.0, $deal->refresh()->remainingPayment());
        $this->assertTrue($deal->isPaidInFull());
    }

    /** @param array<string, mixed> $attributes */
    private function makeDeal(array $attributes = []): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Тест», кв. 10',
            'client_name' => 'Тестовый клиент',
            'client_phone' => '+7 700 000 00 00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            ...$attributes,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'position' => 1,
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
