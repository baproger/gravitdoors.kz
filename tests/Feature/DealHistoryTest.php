<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\Department;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\RelationManagers\EventsRelationManager;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** История сделки: кто, из какого отдела и что сделал. */
class DealHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->actingAs($this->manager);
    }

    public function test_creating_a_deal_starts_the_history(): void
    {
        $deal = $this->makeDeal();

        $event = $deal->events()->firstOrFail();

        $this->assertSame(DealEventType::Created, $event->type);
        $this->assertSame(Department::Sales, $event->department);
        $this->assertSame($this->manager->id, $event->user_id);
    }

    public function test_edits_are_recorded_with_readable_field_names(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['client_phone' => '+7 (777) 123-45-67', 'city' => 'Астана']);

        $event = $deal->events()->where('type', DealEventType::Updated->value)->firstOrFail();

        $this->assertStringContainsString('Телефон', $event->description);
        $this->assertStringContainsString('Город', $event->description);
        $this->assertSame('Астана', $event->changes['city']['to']);
        $this->assertSame('Алматы', $event->changes['city']['from']);
    }

    public function test_technical_fields_do_not_pollute_the_feed(): void
    {
        $deal = $this->makeDeal();
        $before = $deal->events()->count();

        // Служебные поля меняет автоматика — в ленте им не место.
        $deal->forceFill(['stage_entered_at' => now()->addHour()])->save();

        $this->assertSame($before, $deal->refresh()->events()->count());
    }

    public function test_stage_change_is_attributed_to_the_person(): void
    {
        $deal = $this->makeDeal();

        app(DoorProductionService::class)->moveToStage(
            $deal,
            $this->stage('handed_to_production'),
            $this->manager,
        );

        $event = $deal->events()->where('type', DealEventType::StageChanged->value)->latest('id')->firstOrFail();

        $this->assertStringContainsString('Передано в производство', $event->description);
        $this->assertSame($this->manager->id, $event->user_id);
    }

    public function test_workshop_events_land_in_the_same_feed_under_the_factory_department(): void
    {
        $deal = $this->makeDeal();
        $master = User::factory()->create(['role' => UserRole::Master->value]);

        $production = app(DoorProductionService::class);
        $production->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);

        $order = $deal->refresh()->productionOrder;
        $production->startStage($order, $master);
        $production->completeCurrentStage($order->refresh(), $master);

        $factoryEvents = $deal->events()->where('department', Department::Factory->value)->get();

        $this->assertTrue($factoryEvents->isNotEmpty(), 'События цеха не попали в историю сделки');
        $this->assertStringContainsString($master->name, $factoryEvents->last()->description);
    }

    public function test_warehouse_write_off_is_recorded_separately(): void
    {
        $deal = $this->makeDeal();

        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);

        $this->assertSame(
            1,
            $deal->events()->where('department', Department::Warehouse->value)->count(),
        );
    }

    public function test_history_can_be_filtered_by_department(): void
    {
        $deal = $this->makeDeal();
        app(DoorProductionService::class)->moveToStage($deal, $this->stage('handed_to_production'), $this->manager);

        // Колонка приведена к enum, поэтому сравниваем перечислениями.
        $departments = $deal->events()->get()->pluck('department')->unique();

        $this->assertTrue($departments->contains(Department::Sales));
        $this->assertTrue($departments->contains(Department::Warehouse));
    }

    public function test_payment_and_documents_get_their_own_entries(): void
    {
        $deal = $this->makeDeal();

        $deal->update(['prepayment' => 100_000]);
        $deal->update(['documents' => ['deals/act.pdf', 'deals/invoice.pdf']]);

        $this->assertSame(1, $deal->events()->where('type', DealEventType::Payment->value)->count());
        $this->assertSame(1, $deal->events()->where('type', DealEventType::Document->value)->count());
    }

    public function test_department_is_frozen_at_the_moment_of_the_event(): void
    {
        $deal = $this->makeDeal();
        $event = $deal->events()->firstOrFail();

        // Менеджер стал мастером цеха — старая запись остаётся за продажами.
        $this->manager->update(['role' => UserRole::Master->value]);

        $this->assertSame(Department::Sales, $event->refresh()->department);
    }

    public function test_history_tab_opens_on_the_deal_card(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
        $deal = $this->makeDeal();

        $this->assertTrue(
            EventsRelationManager::canViewForRecord(
                $deal,
                EditDeal::class,
            ),
        );
    }

    private function makeDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «История», кв. 3',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            'manager_id' => $this->manager->id,
            'measured_at' => now()->subDay(),
            'due_date' => now()->addWeeks(2),
            'contract_number' => 'ДГ-ТЕСТ-1',
            'contract_date' => now()->subDays(2),
            'documents' => ['deals/contract.pdf'],
            'prepayment' => 50_000,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'category' => 'premium',
            'model' => 'lion',
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
