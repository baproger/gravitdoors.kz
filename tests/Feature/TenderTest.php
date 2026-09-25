<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessLevel;
use App\Enums\ClientType;
use App\Enums\DealEventType;
use App\Enums\DealSource;
use App\Enums\Department;
use App\Enums\Permission;
use App\Enums\TenderLotResult;
use App\Enums\TenderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Tenders\Pages\EditTender;
use App\Filament\Resources\Tenders\RelationManagers\LotsRelationManager;
use App\Models\Deal;
use App\Models\Role;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\DoorProductionService;
use App\Services\TenderService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B2B: своя роль, тендеры с лотами и сделка из выигранного лота.
 *
 * Главное правило — цена сделки из тендера та, что выиграна, а не та, что
 * насчитал бы прайс: заказчик платит по договору.
 */
class TenderTest extends TestCase
{
    use RefreshDatabase;

    private User $b2b;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->b2b = User::factory()->create(['role' => UserRole::B2b->value, 'is_active' => true]);
        $this->actingAs($this->b2b);
    }

    // ---- роль ---------------------------------------------------------------------------

    public function test_b2b_is_a_built_in_sales_role(): void
    {
        $role = Role::byCode(UserRole::B2b->value);

        $this->assertNotNull($role);
        $this->assertTrue($role->is_system);
        $this->assertSame('Менеджер B2B', $role->name);
        $this->assertSame(Department::Sales, Department::forRole(UserRole::B2b));
    }

    public function test_b2b_works_with_own_deals_and_own_tenders(): void
    {
        $this->assertSame(AccessLevel::Own, AccessControl::level('b2b', Permission::WorkTenders));
        $this->assertSame(AccessLevel::Own, AccessControl::level('b2b', Permission::WorkDeals));
        $this->assertSame(AccessLevel::Own, AccessControl::level('b2b', Permission::WorkSalesKanban));
        $this->assertSame(AccessLevel::None, AccessControl::level('b2b', Permission::FinanceCash));

        // Розничному менеджеру тендеры не нужны, бухгалтер их только смотрит.
        $this->assertSame(AccessLevel::None, AccessControl::level('manager', Permission::WorkTenders));
        $this->assertSame(AccessLevel::Read, AccessControl::level('accountant', Permission::WorkTenders));
    }

    public function test_b2b_sees_own_and_unassigned_tenders_only(): void
    {
        $mine = Tender::factory()->create(['manager_id' => $this->b2b->id]);
        $nobodys = Tender::factory()->create(['manager_id' => null]);
        $colleague = User::factory()->create(['role' => UserRole::B2b->value]);
        $foreign = Tender::factory()->create(['manager_id' => $colleague->id]);

        $visible = Tender::query()->visibleTo($this->b2b)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $nobodys->id], $visible);

        $this->get("/admin/tenders/{$mine->id}/edit")->assertOk();
        $this->get("/admin/tenders/{$foreign->id}/edit")->assertNotFound();
    }

    public function test_accountant_reads_tenders_but_cannot_edit(): void
    {
        $tender = Tender::factory()->create();
        $this->actingAs(User::factory()->create(['role' => UserRole::Accountant->value]));

        $this->get('/admin/tenders')->assertOk();
        $this->get("/admin/tenders/{$tender->id}")->assertOk();
        $this->get("/admin/tenders/{$tender->id}/edit")->assertForbidden();
        $this->get('/admin/tenders/create')->assertForbidden();
    }

    public function test_sales_manager_has_no_tenders(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));

        $this->get('/admin/tenders')->assertForbidden();
    }

    public function test_b2b_can_be_responsible_for_a_deal(): void
    {
        Livewire::test(CreateDeal::class)
            ->assertFormFieldExists('manager_id', fn ($field): bool => array_key_exists($this->b2b->id, $field->getOptions()));
    }

    // ---- лот ----------------------------------------------------------------------------

    public function test_lot_is_priced_by_the_price_list_when_saved(): void
    {
        $lot = TenderLot::factory()->create(['quantity' => 10, 'bid_unit_price' => 500_000]);

        $this->assertGreaterThan(0, (float) $lot->list_unit_price);
        $this->assertGreaterThan(0, (float) $lot->estimated_cost);
        $this->assertSame(5_000_000.0, $lot->bidTotal());
        $this->assertNotNull($lot->marginPercent());
    }

    public function test_bid_above_customer_budget_is_flagged(): void
    {
        $lot = TenderLot::factory()->make(['budget_unit_price' => 150_000, 'bid_unit_price' => 160_000]);

        $this->assertTrue($lot->isOverBudget());
    }

    // ---- итоги --------------------------------------------------------------------------

    public function test_any_won_lot_makes_the_tender_won(): void
    {
        $tender = Tender::factory()->create(['status' => TenderStatus::Submitted]);
        TenderLot::factory()->for($tender)->create(['result' => TenderLotResult::Lost]);
        TenderLot::factory()->for($tender)->won()->create();

        app(TenderService::class)->syncStatus($tender);

        $this->assertSame(TenderStatus::Won, $tender->refresh()->status);
    }

    public function test_all_lost_lots_make_the_tender_lost(): void
    {
        $tender = Tender::factory()->create(['status' => TenderStatus::Submitted]);
        TenderLot::factory()->for($tender)->count(2)->create(['result' => TenderLotResult::Lost]);

        app(TenderService::class)->syncStatus($tender);

        $this->assertSame(TenderStatus::Lost, $tender->refresh()->status);
    }

    public function test_declined_tender_is_not_overwritten_by_results(): void
    {
        $tender = Tender::factory()->create(['status' => TenderStatus::Declined]);
        TenderLot::factory()->for($tender)->won()->create();

        app(TenderService::class)->syncStatus($tender);

        $this->assertSame(TenderStatus::Declined, $tender->refresh()->status);
    }

    // ---- сделка из лота -----------------------------------------------------------------

    public function test_won_lot_becomes_a_sales_deal_at_the_tender_price(): void
    {
        $tender = Tender::factory()->create([
            'manager_id' => $this->b2b->id,
            'announcement_number' => '777-1',
            'customer_name' => 'КГУ «Школа № 45»',
            'delivery_due_date' => now()->addDays(40),
        ]);
        $lot = TenderLot::factory()->for($tender)->won()->create([
            'lot_number' => '2', 'quantity' => 30, 'bid_unit_price' => 170_000,
        ]);

        $deal = app(TenderService::class)->createDeal($lot, $this->b2b);

        $this->assertFalse($deal->isFactoryOrder());
        $this->assertSame(ClientType::Company, $deal->client_type);
        $this->assertSame('КГУ «Школа № 45»', $deal->client_company);
        $this->assertSame($tender->customer_bin, $deal->client_bin);
        $this->assertSame(DealSource::Tender, $deal->source);
        $this->assertSame($this->b2b->id, $deal->manager_id);
        $this->assertSame($tender->delivery_due_date->toDateString(), $deal->due_date->toDateString());
        $this->assertStringContainsString('777-1', $deal->title);

        // Одна позиция на всё количество лота, сумма — по тендеру, а не по прайсу.
        $this->assertSame(1, $deal->doorConfigurations()->count());
        $this->assertSame(30, $deal->doorsCount());
        $this->assertSame(5_100_000.0, (float) $deal->contract_price);
        $this->assertSame(5_100_000.0, (float) $deal->total_price);
        $this->assertGreaterThan(0, (float) $deal->cost_price);

        $this->assertSame($deal->id, $lot->refresh()->deal_id);
        $this->assertTrue($deal->events()->where('type', DealEventType::Tender->value)->exists());
    }

    public function test_changing_the_spec_keeps_the_tender_price_and_adds_services(): void
    {
        $lot = TenderLot::factory()->for(Tender::factory()->create(['manager_id' => $this->b2b->id]))
            ->won()->create(['quantity' => 10, 'bid_unit_price' => 200_000]);
        $deal = app(TenderService::class)->createDeal($lot, $this->b2b);
        $costBefore = (float) $deal->cost_price;

        $deal->doorConfigurations()->first()->update(['width' => 1200]);
        $deal->update(['delivery_cost' => 50_000]);
        $deal = app(DoorProductionService::class)->syncPricing($deal);

        $this->assertSame(2_050_000.0, (float) $deal->total_price);
        $this->assertNotSame($costBefore, (float) $deal->cost_price);
    }

    public function test_tender_deal_card_saves_with_hundreds_of_doors(): void
    {
        $lot = TenderLot::factory()->for(Tender::factory()->create(['manager_id' => $this->b2b->id]))
            ->won()->create(['quantity' => 300, 'bid_unit_price' => 150_000]);
        $deal = app(TenderService::class)->createDeal($lot, $this->b2b);

        Livewire::test(EditDeal::class, ['record' => $deal->getRouteKey()])
            ->fillForm(['delivery_cost' => 100_000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(45_100_000.0, (float) $deal->refresh()->total_price);
    }

    public function test_deal_is_not_created_from_a_pending_lot(): void
    {
        $lot = TenderLot::factory()->create(['result' => TenderLotResult::Pending]);

        $this->expectException(ValidationException::class);
        app(TenderService::class)->createDeal($lot, $this->b2b);
    }

    public function test_one_lot_gives_one_deal(): void
    {
        $lot = TenderLot::factory()->won()->create();
        app(TenderService::class)->createDeal($lot, $this->b2b);

        $this->expectException(ValidationException::class);
        app(TenderService::class)->createDeal($lot->refresh(), $this->b2b);
    }

    public function test_deal_needs_customer_phone_and_bin(): void
    {
        $tender = Tender::factory()->create(['contact_phone' => null, 'customer_bin' => null]);
        $lot = TenderLot::factory()->for($tender)->won()->create();

        try {
            app(TenderService::class)->createDeal($lot, $this->b2b);
            $this->fail('Сделка без телефона и БИН создаваться не должна');
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('телефон', $message);
            $this->assertStringContainsString('БИН', $message);
        }

        $this->assertSame(0, Deal::query()->count());
    }

    public function test_b2b_creates_the_deal_from_the_lots_table(): void
    {
        $tender = Tender::factory()->create(['manager_id' => $this->b2b->id, 'status' => TenderStatus::Submitted]);
        $lot = TenderLot::factory()->for($tender)->create();

        Livewire::test(LotsRelationManager::class, ['ownerRecord' => $tender, 'pageClass' => EditTender::class])
            ->callAction(TestAction::make('won')->table($lot))
            ->callAction(TestAction::make('createDeal')->table($lot->refresh()))
            ->assertNotified();

        $this->assertSame(TenderStatus::Won, $tender->refresh()->status);
        $this->assertNotNull($lot->refresh()->deal_id);
        $this->assertTrue($this->b2b->can('update', $lot->deal));
    }

    public function test_lost_lot_keeps_the_winner_for_analysis(): void
    {
        $tender = Tender::factory()->create(['manager_id' => $this->b2b->id, 'status' => TenderStatus::Submitted]);
        $lot = TenderLot::factory()->for($tender)->create();

        Livewire::test(LotsRelationManager::class, ['ownerRecord' => $tender, 'pageClass' => EditTender::class])
            ->callAction(TestAction::make('lost')->table($lot), data: ['winner_name' => 'ТОО «Конкурент»', 'winner_unit_price' => 150_000]);

        $lot->refresh();
        $this->assertSame(TenderLotResult::Lost, $lot->result);
        $this->assertSame('ТОО «Конкурент»', $lot->winner_name);
        $this->assertSame(TenderStatus::Lost, $tender->refresh()->status);
    }

    // ---- количество дверей --------------------------------------------------------------

    public function test_company_orders_doors_in_hundreds_retail_is_capped(): void
    {
        $payload = fn (string $type, int $quantity): array => [
            'title' => 'Поставка дверей',
            'client_name' => 'Сауле Бекова',
            'client_phone' => '+7 (707) 111-22-33',
            'client_type' => $type,
            'client_company' => 'ТОО «Строй-Инвест»',
            'client_bin' => '150340012345',
            'doorConfigurations' => [[
                'category' => 'comfort', 'model' => 'lion', 'quantity' => $quantity,
                'height' => 2050, 'width' => 950, 'opening_side' => 'right',
                'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale',
            ]],
        ];

        Livewire::test(CreateDeal::class)
            ->fillForm($payload(ClientType::Company->value, 300))
            ->call('create')
            ->assertHasNoFormErrors();

        // Та же форма физлица проходит с 30 — значит, отказ именно из-за количества.
        Livewire::test(CreateDeal::class)
            ->fillForm($payload(ClientType::Individual->value, 30))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateDeal::class)
            ->fillForm($payload(ClientType::Individual->value, 300))
            ->call('create')
            ->assertHasFormErrors();
    }

    // ---- напоминание --------------------------------------------------------------------

    public function test_deadline_reminder_reaches_the_responsible(): void
    {
        Tender::factory()->create([
            'manager_id' => $this->b2b->id,
            'status' => TenderStatus::Preparing,
            'deadline_at' => now()->addDay(),
        ]);
        // Поданная заявка и далёкий срок — не повод будить.
        Tender::factory()->create(['manager_id' => $this->b2b->id, 'status' => TenderStatus::Submitted, 'deadline_at' => now()->addDay()]);
        Tender::factory()->create(['manager_id' => $this->b2b->id, 'deadline_at' => now()->addDays(20)]);

        $this->artisan('gravit:daily-check')->assertSuccessful();

        $notification = $this->b2b->notifications()->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Срок подачи заявки близко: 1', $notification->data['title'] ?? '');
    }
}
