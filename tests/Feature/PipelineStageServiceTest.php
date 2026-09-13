<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Exceptions\PipelineException;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\ProductionLog;
use App\Models\User;
use App\Services\PipelineStageService;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Правила настройки воронок: воронку нельзя сломать правкой этапов. */
class PipelineStageServiceTest extends TestCase
{
    use RefreshDatabase;

    private PipelineStageService $stages;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FactoryStageSeeder::class);
        $this->stages = app(PipelineStageService::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_new_stage_goes_before_the_final_ones(): void
    {
        $stage = $this->stages->add(PipelineType::Sales, 'Выезд на объект');

        $this->assertSame(
            ['Новая заявка', 'Замер и расчёт', 'Договор и предоплата', 'Передано в производство', 'Готово к отгрузке', 'Доставка и монтаж', 'Выезд на объект', 'Сделка закрыта'],
            $this->names(PipelineType::Sales),
        );
        $this->assertFalse($stage->is_final);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $stage->code);
    }

    public function test_codes_stay_unique_when_names_differ_only_by_punctuation(): void
    {
        $first = $this->stages->add(PipelineType::Sales, 'Звонок');
        $second = $this->stages->add(PipelineType::Sales, 'Звонок!');

        $this->assertNotSame($first->code, $second->code);
    }

    public function test_duplicate_name_is_rejected_regardless_of_case(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessageMatches('/уже есть/u');

        $this->stages->add(PipelineType::Sales, '  новая   заявка ');
    }

    public function test_same_name_is_allowed_in_the_other_pipeline(): void
    {
        $stage = $this->stages->add(PipelineType::Factory, 'Новая заявка');

        $this->assertSame(PipelineType::Factory, $stage->pipeline_type);
    }

    public function test_too_short_name_is_rejected(): void
    {
        $this->expectException(PipelineException::class);

        $this->stages->add(PipelineType::Sales, 'а');
    }

    public function test_rename_keeps_the_code(): void
    {
        $stage = $this->stage('new');

        $this->stages->rename($stage, 'Входящая заявка');

        $this->assertSame('Входящая заявка', $stage->refresh()->name);
        $this->assertSame('new', $stage->code);
    }

    public function test_move_up_swaps_neighbours_and_updates_the_initial_stage(): void
    {
        $this->stages->move($this->stage('measurement'), -1);

        $this->assertSame('Замер и расчёт', $this->names(PipelineType::Sales)[0]);
        $this->assertTrue($this->stage('measurement')->is_initial);
        $this->assertFalse($this->stage('new')->is_initial);
    }

    public function test_working_stage_does_not_move_below_the_final_ones(): void
    {
        $before = $this->names(PipelineType::Sales);

        $this->stages->move($this->stage('delivery'), 1);

        $this->assertSame($before, $this->names(PipelineType::Sales));
    }

    public function test_drop_places_the_stage_before_the_target(): void
    {
        $this->stages->placeBefore($this->stage('delivery'), $this->stage('measurement'));

        $this->assertSame('Доставка и монтаж', $this->names(PipelineType::Sales)[1]);
    }

    public function test_drop_onto_a_final_stage_keeps_the_stage_working(): void
    {
        $this->stages->placeBefore($this->stage('new'), $this->stage('closed'));

        $names = $this->names(PipelineType::Sales);

        $this->assertSame('Новая заявка', $names[count($names) - 2]);
        $this->assertFalse($this->stage('new')->is_final);
    }

    public function test_orders_are_renumbered_without_gaps(): void
    {
        $this->stages->add(PipelineType::Sales, 'Выезд на объект');
        $this->stages->move($this->stage('contract'), -1);

        $orders = FactoryStage::query()->ofPipeline(PipelineType::Sales)->ordered()->pluck('order')->all();

        $this->assertSame(range(10, count($orders) * 10, 10), $orders);
        $this->assertSame(1, FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('is_initial', true)->count());
    }

    public function test_deleting_a_stage_with_deals_requires_a_target(): void
    {
        $this->dealOn('measurement');

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessageMatches('/Выберите, на какой этап/u');

        $this->stages->delete($this->stage('measurement'));
    }

    public function test_deleting_moves_deals_and_leaves_a_record_in_their_history(): void
    {
        $deal = $this->dealOn('measurement');
        $trashed = $this->dealOn('measurement');
        $trashed->delete();

        $this->stages->delete($this->stage('measurement'), $this->stage('contract'), auth()->user());

        $this->assertDatabaseMissing('factory_stages', ['code' => 'measurement', 'pipeline_type' => 'sales']);
        $this->assertSame($this->stage('contract')->id, $deal->refresh()->current_stage_id);
        // Удалённая в корзину сделка тоже не остаётся без этапа.
        $this->assertSame($this->stage('contract')->id, Deal::withTrashed()->find($trashed->id)->current_stage_id);

        $event = $deal->events()->where('type', DealEventType::StageChanged->value)->latest('id')->firstOrFail();
        $this->assertStringContainsString('удалён из воронки', $event->description);
    }

    public function test_deals_cannot_be_moved_to_another_pipeline(): void
    {
        $this->dealOn('measurement');

        $this->expectException(PipelineException::class);

        $this->stages->delete($this->stage('measurement'), $this->stage('welding', PipelineType::Factory));
    }

    public function test_workshop_stage_with_history_cannot_be_deleted(): void
    {
        ProductionLog::factory()->create(['stage_id' => $this->stage('welding', PipelineType::Factory)->id]);

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessageMatches('/зарплата/u');

        $this->stages->delete($this->stage('welding', PipelineType::Factory));
    }

    public function test_automation_stage_cannot_be_deleted(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessageMatches('/автоматику/u');

        $this->stages->delete($this->stage('handed_to_production'));
    }

    public function test_automation_stage_cannot_be_hidden(): void
    {
        $this->expectException(PipelineException::class);

        $this->stages->setActive($this->stage('qc_packing', PipelineType::Factory), false);
    }

    public function test_automation_moves_to_the_new_stage_and_stays_unique(): void
    {
        $this->stages->setAutomation($this->stage('contract'), true);

        $this->assertTrue($this->stage('contract')->triggers_production);
        $this->assertFalse($this->stage('handed_to_production')->triggers_production);
        $this->assertSame(1, FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('triggers_production', true)->count());
    }

    public function test_final_sales_stage_cannot_hand_off_to_production(): void
    {
        $this->expectException(PipelineException::class);

        $this->stages->setAutomation($this->stage('closed'), true);
    }

    public function test_the_last_working_stage_cannot_be_removed(): void
    {
        foreach (['metal_cutting', 'welding', 'painting', 'insulation_mdf'] as $code) {
            $this->stages->delete($this->stage($code, PipelineType::Factory));
        }

        $this->expectException(PipelineException::class);
        $this->expectExceptionMessageMatches('/хотя бы один рабочий этап/u');

        $this->stages->delete($this->stage('hardware', PipelineType::Factory));
    }

    public function test_stage_with_deals_cannot_be_hidden(): void
    {
        $this->dealOn('delivery');

        $this->expectException(PipelineException::class);

        $this->stages->setActive($this->stage('delivery'), false);
    }

    public function test_hidden_stage_is_skipped_by_the_funnel(): void
    {
        $this->stages->setActive($this->stage('delivery'), false);

        $this->assertSame('closed', $this->stage('ready_to_ship')->next()?->code);
    }

    public function test_settings_keep_only_what_fits_the_pipeline(): void
    {
        $this->stages->updateSettings($this->stage('contract'), [
            'required_fields' => ['client_phone', 'client_phone', 'nonsense'],
            'operation_cost' => 5_000,
            'estimated_hours' => 12,
            'description' => '  Подписываем договор  ',
        ]);

        $sales = $this->stage('contract');
        $this->assertSame(['client_phone'], $sales->required_fields);
        $this->assertEqualsWithDelta(0, (float) $sales->operation_cost, 0.01);
        $this->assertSame('Подписываем договор', $sales->description);

        $this->stages->updateSettings($this->stage('welding', PipelineType::Factory), [
            'required_fields' => ['client_phone'],
            'operation_cost' => 7_000,
        ]);

        $factory = $this->stage('welding', PipelineType::Factory);
        $this->assertSame([], $factory->required_fields);
        $this->assertEqualsWithDelta(7_000, (float) $factory->operation_cost, 0.01);
    }

    public function test_unknown_colour_is_rejected(): void
    {
        $this->expectException(PipelineException::class);

        $this->stages->recolor($this->stage('new'), 'fuchsia');
    }

    /** @return list<string> */
    private function names(PipelineType $pipeline): array
    {
        return FactoryStage::query()->ofPipeline($pipeline)->ordered()->pluck('name')->all();
    }

    private function stage(string $code, PipelineType $pipeline = PipelineType::Sales): FactoryStage
    {
        return FactoryStage::query()->ofPipeline($pipeline)->where('code', $code)->firstOrFail();
    }

    private function dealOn(string $code): Deal
    {
        return Deal::create([
            'title' => 'Сделка на этапе '.$code.' '.uniqid(),
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage($code)->id,
        ]);
    }
}
