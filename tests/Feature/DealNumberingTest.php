<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Models\Deal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_are_sequential_within_each_pipeline(): void
    {
        $sales = [$this->make(PipelineType::Sales), $this->make(PipelineType::Sales)];
        $factory = $this->make(PipelineType::Factory);
        $third = $this->make(PipelineType::Sales);

        $year = now()->format('y');

        $this->assertSame("GRV-{$year}-0001", $sales[0]->number);
        $this->assertSame("GRV-{$year}-0002", $sales[1]->number);
        // Наряд не должен «съедать» номер из реестра продаж.
        $this->assertSame("PRD-{$year}-0001", $factory->number);
        $this->assertSame("GRV-{$year}-0003", $third->number);
    }

    public function test_deleted_deal_does_not_free_its_number(): void
    {
        $first = $this->make(PipelineType::Sales);
        $first->delete();

        $second = $this->make(PipelineType::Sales);

        $this->assertNotSame($first->number, $second->number);
    }

    public function test_every_deal_gets_a_unique_track_hash(): void
    {
        $hashes = collect(range(1, 5))->map(fn (): string => $this->make(PipelineType::Sales)->qr_code_hash);

        $this->assertCount(5, $hashes->unique());
        $this->assertSame(24, mb_strlen($hashes->first()));
    }

    private function make(PipelineType $type): Deal
    {
        return Deal::create([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::New,
            'pipeline_type' => $type,
        ]);
    }
}
