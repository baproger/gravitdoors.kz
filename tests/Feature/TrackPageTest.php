<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Services\DoorProductionService;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
    }

    public function test_client_sees_order_status_by_hash(): void
    {
        $deal = $this->makeDeal();

        $this->get(route('track.show', $deal->qr_code_hash))
            ->assertOk()
            ->assertSee($deal->number)
            ->assertSee($deal->client_name)
            ->assertSee($deal->status_id->publicLabel());
    }

    public function test_unknown_hash_returns_404(): void
    {
        $this->get('/track/'.str_repeat('z', 24))->assertNotFound();
    }

    public function test_internal_numbers_are_not_exposed(): void
    {
        $deal = $this->makeDeal();
        $deal->update(['cost_price' => 123_456, 'notes' => 'Внутренняя заметка менеджера']);

        $response = $this->get(route('track.show', $deal->qr_code_hash))->assertOk();

        $response->assertDontSee('123 456');
        $response->assertDontSee('Внутренняя заметка менеджера');
    }

    public function test_production_order_hash_redirects_content_to_sales_deal(): void
    {
        $deal = $this->makeDeal();
        $order = app(DoorProductionService::class)->handOffToProduction($deal);

        // По QR наряда клиент видит свою сделку, а не внутренний номер PRD-…
        $this->get(route('track.show', $order->qr_code_hash))
            ->assertOk()
            ->assertSee($deal->number)
            ->assertDontSee($order->number);
    }

    private function makeDeal(): Deal
    {
        $deal = Deal::create([
            'title' => 'ЖК «Тест»',
            'client_name' => 'Иван Петров',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
            'total_price' => 250_000,
        ]);

        DoorConfiguration::create([
            'deal_id' => $deal->id,
            'height' => 2050,
            'width' => 950,
            'opening_side' => 'right',
            'quantity' => 1,
            'metal_thickness' => 'metal_1_5',
            'lock_system' => 'lock_kale',
        ]);

        return $deal->refresh();
    }
}
