<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\ProductionLog;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Фабрики должны собирать валидные записи — иначе они бесполезны в тестах. */
class FactorySanityTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{class-string}> */
    public static function models(): array
    {
        return [
            [User::class],
            [FactoryStage::class],
            [Deal::class],
            [DoorConfiguration::class],
            [MaterialStock::class],
            [DoorOption::class],
            [ProductionLog::class],
            [StockMovement::class],
        ];
    }

    #[DataProvider('models')]
    public function test_factory_creates_persisted_model(string $model): void
    {
        $record = $model::factory()->create();

        $this->assertTrue($record->exists);
        $this->assertDatabaseHas($record->getTable(), ['id' => $record->id]);
    }
}
