<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Канбан не должен тянуть из базы все открытые сделки этапа. */
class KanbanBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_column_loads_only_a_page_of_cards_but_counts_them_all(): void
    {
        $stage = $this->stage('contract');
        $this->makeDeals($stage, 12);

        $board = app(DoorProductionService::class)->board(PipelineType::Sales, perColumn: 5);
        $column = $board->firstWhere('code', 'contract');

        $this->assertCount(5, $column->deals, 'В колонку загрузилось больше карточек, чем лимит');
        $this->assertSame(12, $column->deals_count, 'Счётчик колонки должен показывать все сделки');
    }

    public function test_expanded_column_loads_more(): void
    {
        $stage = $this->stage('contract');
        $this->makeDeals($stage, 12);

        $board = app(DoorProductionService::class)->board(
            PipelineType::Sales,
            perColumn: 5,
            expandedStageIds: [$stage->id],
        );

        $this->assertCount(12, $board->firstWhere('code', 'contract')->deals);
    }

    public function test_closed_deals_stay_off_the_board(): void
    {
        $stage = $this->stage('contract');
        $this->makeDeals($stage, 3);
        Deal::query()->limit(1)->update(['status_id' => DealStatus::Cancelled->value]);

        $column = app(DoorProductionService::class)->board(PipelineType::Sales)->firstWhere('code', 'contract');

        $this->assertSame(2, $column->deals_count);
    }

    public function test_search_filters_the_whole_board(): void
    {
        $stage = $this->stage('contract');
        $this->makeDeals($stage, 3);
        Deal::query()->first()->update(['client_name' => 'Уникальный Заказчик']);

        $column = app(DoorProductionService::class)->board(PipelineType::Sales, search: 'Уникальный')
            ->firstWhere('code', 'contract');

        $this->assertSame(1, $column->deals_count);
        $this->assertCount(1, $column->deals);
    }

    private function makeDeals(FactoryStage $stage, int $count): void
    {
        Deal::factory()->count($count)->create([
            'pipeline_type' => PipelineType::Sales->value,
            'current_stage_id' => $stage->id,
            'status_id' => DealStatus::InWork,
        ]);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
