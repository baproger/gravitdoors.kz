<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Кнопка «Замерял»: замерщик записывает результат выезда с инфопанели.
 *
 * Карточка сделки ему не открывается, поэтому действие живёт на единственном
 * доступном ему экране, а право проверяется отдельной политикой `measure`.
 */
class MeasurementResultTest extends TestCase
{
    use RefreshDatabase;

    private User $surveyor;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);

        $this->surveyor = User::factory()->create(['role' => UserRole::Surveyor->value, 'name' => 'Ербол Замерщик']);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value, 'name' => 'Айгуль Менеджер']);
    }

    public function test_surveyor_sees_the_button_for_todays_and_overdue_visits(): void
    {
        $today = $this->deal(['client_name' => 'Сегодняшний', 'measured_at' => today()->setTime(11, 0)]);
        $missed = $this->deal(['client_name' => 'Позавчерашний', 'measured_at' => today()->subDays(2)->setTime(10, 0)]);

        $this->actingAs($this->surveyor)->get('/admin')
            ->assertOk()
            ->assertSee('Сегодняшний')
            ->assertSee('Позавчерашний')
            ->assertSee("mountAction('measure', { deal: {$today->id} })", false)
            ->assertSee("mountAction('measure', { deal: {$missed->id} })", false);
    }

    public function test_surveyor_records_height_width_and_comment_into_the_deal(): void
    {
        $deal = $this->deal(['measured_at' => today()->setTime(11, 0)]);

        $this->actingAs($this->surveyor);

        Livewire::test(Dashboard::class)
            ->mountAction('measure', ['deal' => $deal->id])
            ->assertActionMounted('measure')
            ->setActionData([
                'measurement_height' => 2100,
                'measurement_width' => 900,
                'measurement_comment' => 'Проём кривой, нужен доборный профиль',
            ])
            ->callMountedAction()
            ->assertHasNoErrors();

        $deal->refresh();

        $this->assertSame(2100, $deal->measurement_height);
        $this->assertSame(900, $deal->measurement_width);
        $this->assertSame('Проём кривой, нужен доборный профиль', $deal->measurement_comment);
        $this->assertTrue($deal->hasMeasurement());
        $this->assertSame($this->surveyor->id, $deal->measurement_by_id);
        $this->assertSame('2100 × 900 мм', $deal->measurementSize());
    }

    /** Одна запись «Замер», а не она же плюс «Изменено: Высота замера, …». */
    public function test_history_gets_exactly_one_survey_entry(): void
    {
        $deal = $this->deal(['measured_at' => today()->setTime(11, 0)]);

        $this->record($deal);

        $survey = $deal->events()->where('type', DealEventType::Survey)->get();
        $updates = $deal->events()->where('type', DealEventType::Updated)->count();

        $this->assertCount(1, $survey);
        $this->assertSame(0, $updates);
        $this->assertStringContainsString('2100 × 900 мм', $survey->first()->description);
        $this->assertSame($this->surveyor->id, $survey->first()->user_id);
    }

    public function test_manager_is_notified_and_surveyor_is_not(): void
    {
        $deal = $this->deal(['measured_at' => today()->setTime(11, 0)]);

        // Замерщик уже получил «Замер назначен» при создании сделки — считаем,
        // что после записи результата ему ничего нового не приходит.
        $before = $this->surveyor->notifications()->count();

        $this->record($deal);

        $this->assertSame(1, $this->manager->notifications()->count());
        $this->assertSame($before, $this->surveyor->notifications()->count());

        $notification = $this->manager->notifications()->first();
        $this->assertStringContainsString($deal->number, $notification->data['title']);
        $this->assertStringContainsString('2100 × 900 мм', $notification->data['body']);
    }

    public function test_closed_deal_and_factory_order_refuse_the_measurement(): void
    {
        $closed = $this->deal(['measured_at' => today()->setTime(11, 0), 'status_id' => DealStatus::Completed]);
        $order = Deal::create([
            'title' => 'Наряд',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(2),
            'status_id' => DealStatus::InProduction,
            'pipeline_type' => PipelineType::Factory,
            'measured_at' => today()->setTime(11, 0),
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Factory)?->id,
        ]);

        $this->assertFalse($this->surveyor->can('measure', $closed));
        $this->assertFalse($this->surveyor->can('measure', $order));
    }

    /** Замер без назначенной даты выезда — не замер, а правка карточки. */
    public function test_deal_without_a_scheduled_visit_refuses_the_measurement(): void
    {
        $this->assertFalse($this->surveyor->can('measure', $this->deal()));
    }

    public function test_manager_cannot_measure_someone_elses_deal(): void
    {
        $stranger = User::factory()->create(['role' => UserRole::Manager->value]);
        $deal = $this->deal(['measured_at' => today()->setTime(11, 0)]);

        $this->assertTrue($this->manager->can('measure', $deal));
        $this->assertFalse($stranger->can('measure', $deal));
    }

    /** Замерщик по-прежнему не видит ни списка сделок, ни воронки. */
    public function test_the_button_does_not_open_the_deal_card_to_the_surveyor(): void
    {
        $this->deal(['measured_at' => today()->setTime(11, 0)]);

        $this->actingAs($this->surveyor);

        $this->get('/admin/deals')->assertForbidden();
        $this->get('/admin/kanban/sales')->assertForbidden();
    }

    private function record(Deal $deal): void
    {
        $this->actingAs($this->surveyor);

        Livewire::test(Dashboard::class)
            ->mountAction('measure', ['deal' => $deal->id])
            ->setActionData([
                'measurement_height' => 2100,
                'measurement_width' => 900,
                'measurement_comment' => null,
            ])
            ->callMountedAction()
            ->assertHasNoErrors();

        $deal->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function deal(array $attributes = []): Deal
    {
        return Deal::create(array_merge([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Абая, 1',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(3),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $this->manager->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'measurement')->value('id'),
        ], $attributes));
    }
}
