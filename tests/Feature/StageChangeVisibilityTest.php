<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
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

/**
 * Менеджер двинул сделку — директор узнаёт об этом, не открывая карточку.
 *
 * Раньше перенос писался только в историю внутри сделки, и со стороны панели
 * было не видно, что заказ вообще куда-то переехал.
 */
class StageChangeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);

        $this->director = User::factory()->create(['role' => UserRole::Admin->value, 'name' => 'Директор']);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value, 'name' => 'Айгуль Менеджер']);
    }

    public function test_director_is_notified_when_a_manager_moves_a_deal(): void
    {
        $deal = $this->deal();

        $this->move($deal, 'contract');

        $this->assertSame(1, $this->director->notifications()->count());

        $notification = $this->director->notifications()->first();

        $this->assertStringContainsString($deal->number, $notification->data['title']);
        $this->assertStringContainsString('Замер и расчёт', $notification->data['body']);
        $this->assertStringContainsString('Договор', $notification->data['body']);
        $this->assertStringContainsString('перенёс Айгуль Менеджер', $notification->data['body']);
    }

    /** Кнопка нажата им самим — уведомлять его же незачем. */
    public function test_the_person_who_moved_the_deal_gets_no_notification(): void
    {
        $deal = $this->deal();

        $this->move($deal, 'contract');
        $this->assertSame(0, $this->manager->notifications()->count());

        $before = $this->director->notifications()->count();
        app(DoorProductionService::class)->moveToStage($this->deal(), $this->stage('contract'), $this->director);
        $this->assertSame($before, $this->director->notifications()->count());
    }

    /** Директор перевёл чужую сделку — её менеджер должен об этом узнать. */
    public function test_manager_is_notified_when_someone_else_moves_their_deal(): void
    {
        $deal = $this->deal();

        app(DoorProductionService::class)->moveToStage($deal, $this->stage('contract'), $this->director);

        $this->assertSame(1, $this->manager->notifications()->count());

        $notification = $this->manager->notifications()->first();

        $this->assertStringContainsString($deal->number, $notification->data['title']);
        $this->assertStringContainsString('перенёс Директор', $notification->data['body']);
        $this->assertStringContainsString("/admin/deals/{$deal->id}", $notification->data['actions'][0]['url']);
    }

    /** Завод закрыл наряд, сделка сама стала «Готово к отгрузке» — менеджеру пора к клиенту. */
    public function test_manager_is_notified_when_the_factory_finishes_the_order(): void
    {
        $deal = $this->handedDeal();
        $worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Рабочий']);
        $order = $deal->productionOrder()->firstOrFail();

        app(DoorProductionService::class)->finishProduction($order, $worker);

        $ready = $this->manager->notifications()->get()
            ->first(fn ($n): bool => str_contains($n->data['body'], 'Завод закончил заказ'));

        $this->assertNotNull($ready, 'Менеджер не получил уведомление о готовом заказе');
        $this->assertStringContainsString($deal->number, $ready->data['title']);
        $this->assertStringContainsString('Готово к отгрузке', $ready->data['body']);
        $this->assertStringNotContainsString('перенёс', $ready->data['body']);
        $this->assertSame('Открыть сделку', $ready->data['actions'][0]['label']);
        $this->assertStringContainsString("/admin/deals/{$deal->id}", $ready->data['actions'][0]['url']);

        // Сам рабочий и директор-автор передачи: рабочему — ничего, директору — да.
        $this->assertSame(0, $worker->notifications()->count());
        $this->assertTrue($this->director->notifications()->get()
            ->contains(fn ($n): bool => str_contains($n->data['body'], 'Завод закончил заказ')));
    }

    /** Наряд идёт по 13 этапам цеха: уведомление на каждый залило бы колокольчик. */
    public function test_factory_order_moves_do_not_notify_anyone(): void
    {
        $order = $this->order();
        $next = FactoryStage::firstOf(PipelineType::Factory)?->next();

        app(DoorProductionService::class)->moveToStage($order, $next, $this->manager);

        $this->assertSame(0, $this->director->notifications()->count());
    }

    public function test_deal_list_shows_who_moved_the_deal_and_when(): void
    {
        $deal = $this->deal();
        $this->move($deal, 'contract');

        $this->actingAs($this->director)->get('/admin/deals')
            ->assertOk()
            ->assertSee('перенёс Айгуль Менеджер');
    }

    /** Старые сделки без журнала заходов не должны ронять список. */
    public function test_deal_without_a_stage_visit_shows_no_line(): void
    {
        $deal = $this->deal();
        $deal->stageVisits()->delete();

        $this->assertNull($deal->fresh()->latestStageVisit);

        $this->actingAs($this->director)->get('/admin/deals')->assertOk();
    }

    /** Именно под менеджером: журнал заходов пишет того, кто вошёл, а не актора. */
    private function move(Deal $deal, string $code): void
    {
        $this->actingAs($this->manager);

        app(DoorProductionService::class)->moveToStage($deal, $this->stage($code), $this->manager);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }

    /** Сделка, готовая уйти на «Договор»: этап требует замер, двери и срок. */
    private function deal(): Deal
    {
        $deal = Deal::create([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Абая, 1',
            'city' => 'Алматы',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(3),
            'measured_at' => today()->setTime(11, 0),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $this->manager->id,
            'current_stage_id' => $this->stage('measurement')->id,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'category' => 'premium',
            'model' => 'lion',
            'height' => 2050,
            'width' => 950,
            'quantity' => 1,
            'opening_side' => 'right',
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }

    /** Сделка, переданная директором в цех: договор, чек, наряд создан. */
    private function handedDeal(): Deal
    {
        $deal = $this->deal();
        $deal->forceFill([
            'current_stage_id' => $this->stage('contract')->id,
            'contract_number' => 'ДГ-1',
            'contract_date' => now(),
            'documents' => ['deals/contract.pdf'],
            'prepayment' => 50_000,
        ])->save();

        app(DoorProductionService::class)->moveToStage($deal->refresh(), $this->stage('handed_to_production'), $this->director);

        return $deal->refresh();
    }

    private function order(): Deal
    {
        return Deal::create([
            'title' => 'Наряд',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InProduction,
            'pipeline_type' => PipelineType::Factory,
            'parent_deal_id' => $this->deal()->id,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Factory)?->id,
            'production_started_at' => now(),
        ]);
    }
}
