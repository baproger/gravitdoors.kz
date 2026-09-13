<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\FactoryStages\Pages\PipelineStages;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Страница «Этапы воронок»: всё, что делает администратор, доходит до базы по правилам. */
class PipelineStagesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FactoryStageSeeder::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_page_shows_the_selected_pipeline(): void
    {
        Livewire::test(PipelineStages::class)
            ->assertSee('Этапы в работе')
            ->assertSee('Завершающие этапы')
            ->assertSee('Замер и расчёт')
            ->set('pipeline', 'factory')
            ->assertSee('Сварка каркаса')
            ->assertDontSee('Замер и расчёт');
    }

    public function test_page_opens_from_the_menu_link(): void
    {
        $this->get('/admin/factory-stages')->assertOk()->assertSee('Этапы воронок');
        $this->get('/admin/factory-stages?pipeline=factory')->assertOk()->assertSee('Раскрой металла');
    }

    public function test_stage_is_added_from_the_page(): void
    {
        Livewire::test(PipelineStages::class)
            ->set('newStageName', 'Выезд на объект')
            ->call('addStage')
            ->assertSet('newStageName', '');

        $this->assertDatabaseHas('factory_stages', ['name' => 'Выезд на объект', 'pipeline_type' => 'sales', 'is_final' => false]);
    }

    public function test_failed_rename_restores_the_real_name_in_the_field(): void
    {
        $stage = $this->stage('new');

        Livewire::test(PipelineStages::class)
            ->call('renameStage', $stage->id, 'Замер и расчёт')
            ->assertDispatched('stage-name-reset', id: $stage->id, name: 'Новая заявка');

        $this->assertSame('Новая заявка', $stage->refresh()->name);
    }

    public function test_drag_and_drop_reorders_stages(): void
    {
        Livewire::test(PipelineStages::class)
            ->call('dropStage', $this->stage('delivery')->id, $this->stage('new')->id);

        $this->assertSame('delivery', FactoryStage::query()->ofPipeline(PipelineType::Sales)->ordered()->value('code'));
    }

    public function test_delete_action_moves_deals_to_the_chosen_stage(): void
    {
        $deal = Deal::create([
            'title' => 'Сделка на замере',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('measurement')->id,
        ]);

        Livewire::test(PipelineStages::class)
            ->callAction('deleteStage', data: ['move_to' => $this->stage('contract')->id], arguments: ['stage' => $this->stage('measurement')->id])
            ->assertHasNoActionErrors();

        $this->assertSame($this->stage('contract')->id, $deal->refresh()->current_stage_id);
        $this->assertDatabaseMissing('factory_stages', ['code' => 'measurement', 'pipeline_type' => 'sales']);
    }

    public function test_settings_action_saves_required_fields(): void
    {
        Livewire::test(PipelineStages::class)
            ->callAction('stageSettings', data: [
                'description' => 'Подписываем договор',
                'estimated_hours' => 24,
                'required_fields' => ['client_phone', 'city'],
            ], arguments: ['stage' => $this->stage('contract')->id])
            ->assertHasNoActionErrors();

        $stage = $this->stage('contract');
        $this->assertSame(['client_phone', 'city'], $stage->required_fields);
        $this->assertSame('Подписываем договор', $stage->description);
    }

    public function test_automation_is_reassigned_from_the_menu(): void
    {
        Livewire::test(PipelineStages::class)->call('toggleAutomation', $this->stage('contract')->id);

        $this->assertTrue($this->stage('contract')->triggers_production);
        $this->assertFalse($this->stage('handed_to_production')->triggers_production);
    }

    public function test_manager_cannot_change_stages(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));

        $this->get('/admin/factory-stages')->assertForbidden();
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
