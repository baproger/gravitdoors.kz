<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Назначили дату замера — замерщик узнаёт об этом из системы, а не на словах. */
class SurveyNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_surveyor_is_notified_when_the_survey_date_is_set(): void
    {
        $surveyor = $this->user(UserRole::Surveyor);
        $deal = $this->makeDeal();

        $deal->update(['measured_at' => now()->addDays(2)]);

        $this->assertSame(1, $surveyor->notifications()->count());

        $data = $surveyor->notifications()->first()->data;

        $this->assertStringContainsString('Замер', $data['title']);
        $this->assertStringContainsString($deal->number, $data['body']);
        $this->assertStringContainsString('ул. Тестовая 1', $data['body']);
    }

    public function test_rescheduling_notifies_again(): void
    {
        $surveyor = $this->user(UserRole::Surveyor);
        $deal = $this->makeDeal();

        $deal->update(['measured_at' => now()->addDays(2)]);
        $deal->update(['measured_at' => now()->addDays(5)]);

        $this->assertSame(2, $surveyor->notifications()->count());
    }

    public function test_other_edits_do_not_spam_the_surveyor(): void
    {
        $surveyor = $this->user(UserRole::Surveyor);
        $deal = $this->makeDeal();
        $deal->update(['measured_at' => now()->addDays(2)]);

        $deal->update(['notes' => 'Клиент просил перезвонить']);
        $deal->update(['title' => 'Новое название']);

        $this->assertSame(1, $surveyor->notifications()->count());
    }

    public function test_managers_are_not_notified_about_surveys(): void
    {
        $manager = $this->user(UserRole::Manager, 'manager@test.kz');
        $deal = $this->makeDeal();

        $deal->update(['measured_at' => now()->addDays(2)]);

        $this->assertSame(0, $manager->notifications()->count());
    }

    public function test_clearing_the_date_does_not_notify(): void
    {
        $surveyor = $this->user(UserRole::Surveyor);
        $deal = $this->makeDeal();
        $deal->update(['measured_at' => now()->addDays(2)]);

        $deal->update(['measured_at' => null]);

        $this->assertSame(1, $surveyor->notifications()->count());
    }

    private function user(UserRole $role, string $email = 'surveyor@test.kz'): User
    {
        return User::factory()->create(['role' => $role->value, 'email' => $email, 'is_active' => true]);
    }

    private function makeDeal(): Deal
    {
        return Deal::create([
            'title' => 'Замер по адресу',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'client_address' => 'ул. Тестовая 1',
            'city' => 'Алматы',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'new')->value('id'),
        ]);
    }
}
