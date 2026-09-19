<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Замер — со временем: замерщик получает час выезда, утром — список на день,
 * а просроченный замер приходит директору и ответственному менеджеру.
 */
class MeasurementTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $director;

    private User $surveyor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->director = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->surveyor = User::factory()->create(['role' => UserRole::Surveyor->value]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_measurement_keeps_its_time_and_the_surveyor_sees_it(): void
    {
        $deal = $this->deal();

        Livewire::actingAs($this->manager)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['measured_at' => '2026-09-22 14:30:00', 'client_address' => 'ул. Абая, 1', 'city' => 'Алматы'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-09-22 14:30', $deal->refresh()->measured_at->format('Y-m-d H:i'));

        $notification = $this->surveyor->notifications()->first();
        $this->assertNotNull($notification, 'замерщик получает уведомление');
        $this->assertSame('Замер 22.09.2026 в 14:30', $notification->data['title']);
        $this->assertStringContainsString('ул. Абая, 1', $notification->data['body']);

        $this->assertStringContainsString('22.09.2026 14:30', $deal->events()->latest('id')->first()->description);
    }

    public function test_overdue_days_count_calendar_days_not_hours(): void
    {
        Carbon::setTestNow('2026-09-22 09:00:00');
        $deal = $this->deal(['measured_at' => '2026-09-21 20:00:00']);

        $this->assertTrue($deal->isMeasurementOverdue());
        $this->assertSame(1, $deal->measurementOverdueDays());

        // Сегодняшний замер, даже если час уже прошёл, просроченным не считается.
        $today = $this->deal(['measured_at' => '2026-09-22 08:00:00']);
        $this->assertFalse($today->isMeasurementOverdue());
    }

    public function test_daily_check_sends_todays_measurements_to_surveyors_and_the_manager(): void
    {
        Carbon::setTestNow('2026-09-22 08:30:00');
        $this->deal(['measured_at' => '2026-09-22 11:00:00', 'client_address' => 'пр. Достык, 5', 'client_name' => 'Асель']);
        $this->deal(['measured_at' => '2026-09-23 11:00:00']); // завтра — не сегодня

        $this->artisan('gravit:daily-check')->assertSuccessful();

        // Первое уведомление замерщику — «замер назначен» при создании сделки; ищем утреннее.
        $mine = $this->surveyor->notifications()->where('data->title', 'Замеры сегодня: 1')->first();
        $this->assertNotNull($mine);
        $this->assertStringContainsString('11:00 — Асель, пр. Достык, 5', $mine->data['body']);

        $this->assertTrue($this->manager->notifications()->where('data->title', 'Замеры сегодня: 1')->exists());
        $this->assertFalse($this->director->notifications()->where('data->title', 'Замеры сегодня: 1')->exists());
    }

    public function test_daily_check_sends_overdue_measurements_to_the_director_and_the_manager(): void
    {
        Carbon::setTestNow('2026-09-22 08:30:00');
        $other = User::factory()->create(['role' => UserRole::Manager->value]);
        $deal = $this->deal(['measured_at' => '2026-09-19 10:00:00']);

        $this->artisan('gravit:daily-check')->assertSuccessful();

        foreach ([$this->director, $this->manager] as $user) {
            $notification = $user->notifications()->where('data->title', 'Замеры просрочены: 1')->first();
            $this->assertNotNull($notification, $user->role->value.' получает просрочку');
            $this->assertStringContainsString($deal->number, $notification->data['body']);
            $this->assertStringContainsString('19.09.2026 10:00', $notification->data['body']);
            $this->assertStringContainsString('+3 дн.', $notification->data['body']);
        }

        // Чужой менеджер и замерщик о просрочке не узнают: это не их зона.
        $this->assertFalse($other->notifications()->where('data->title', 'Замеры просрочены: 1')->exists());
        $this->assertFalse($this->surveyor->notifications()->where('data->title', 'Замеры просрочены: 1')->exists());
    }

    /** @param array<string, mixed> $attributes */
    private function deal(array $attributes = []): Deal
    {
        return Deal::create(array_merge([
            'title' => 'Сделка с замером',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(3),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $this->manager->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'measurement')->value('id'),
        ], $attributes));
    }
}
