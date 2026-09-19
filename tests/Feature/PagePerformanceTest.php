<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\User;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Страницы не должны тяжелеть от того, что в системе накопились заказы.
 *
 * Сервер у завода маленький: 1 ГБ памяти и три процесса PHP. Лишний запрос на
 * каждую строку списка незаметен на десяти сделках и кладёт панель на тысяче —
 * а заметит это не разработчик, а менеджер, которому «всё тормозит».
 *
 * Так уже было трижды: кнопка «Пересчитать цену» спрашивала у каждой строки,
 * есть ли позиции (25 запросов на страницу), кнопка «Передать в производство»
 * искала завершённый наряд (ещё 17), инфопанель считала часы на этапе по
 * каждому наряду в цеху (80 запросов и росло), а склад дважды на строку
 * выяснял, можно ли удалить материал (50). Обычные тесты этого не видят:
 * страница открывается, данные верные, просто запросов в разы больше.
 */
class PagePerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Потолок запросов на страницу. Числа взяты с запасом от замеренных:
     * тест ловит не единицу сверху, а возврат к запросу на строку.
     */
    private const CEILING = [
        '/admin' => 55,
        '/admin/deals' => 30,
        '/admin/kanban/sales' => 22,
        '/admin/kanban/factory' => 22,
        '/admin/clients' => 12,
        '/admin/finance' => 22,
        '/admin/invoices' => 32,
        '/admin/debts' => 12,
        '/admin/overdue-deals' => 16,
        '/admin/material-stocks' => 20,
        '/admin/payroll' => 12,
        '/admin/stock-movements' => 12,
    ];

    public function test_pages_do_not_get_heavier_as_the_factory_fills_up(): void
    {
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));

        $empty = $this->countQueriesPerPage();
        $this->fillWith(deals: 40, materials: 40);
        $some = $this->countQueriesPerPage();
        $this->fillWith(deals: 120, materials: 0);
        $many = $this->countQueriesPerPage();

        foreach (array_keys(self::CEILING) as $page) {
            // Пустая база против полной страницы: ловит запрос на каждую строку.
            $this->assertLessThanOrEqual(
                $empty[$page] + 12,
                $some[$page],
                "{$page}: с данными запросов стало {$some[$page]} вместо {$empty[$page]} — похоже на запрос в каждой строке"
            );

            // Вчетверо больше сделок — столько же запросов: ловит обход всей таблицы.
            $this->assertLessThanOrEqual(
                $some[$page] + 2,
                $many[$page],
                "{$page}: запросы растут вместе с числом сделок ({$some[$page]} → {$many[$page]})"
            );

            $this->assertLessThanOrEqual(
                self::CEILING[$page],
                $many[$page],
                "{$page}: {$many[$page]} запросов при потолке ".self::CEILING[$page]
            );
        }
    }

    /** @return array<string, int> */
    private function countQueriesPerPage(): array
    {
        $counts = [];

        foreach (array_keys(self::CEILING) as $page) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($page)->assertOk();
            $counts[$page] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $counts;
    }

    private function fillWith(int $deals, int $materials): void
    {
        $sales = FactoryStage::firstOf(PipelineType::Sales);
        $factory = FactoryStage::firstOf(PipelineType::Factory);
        $managers = User::factory()->count(3)->create(['role' => UserRole::Manager->value]);
        $offset = Deal::count();

        for ($i = $offset; $i < $offset + $deals; $i++) {
            $deal = Deal::create([
                'title' => "Сделка {$i}",
                'client_name' => "Клиент {$i}",
                'client_phone' => '+7 700 000-00-'.str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT),
                'total_price' => 100_000 + $i,
                'prepayment' => $i % 3 === 0 ? 100_000 + $i : 0,
                'due_date' => now()->addDays($i % 30 - 10),
                'status_id' => DealStatus::InWork,
                'pipeline_type' => PipelineType::Sales,
                'current_stage_id' => $sales?->id,
                'manager_id' => $managers[$i % 3]->id,
            ]);

            // Каждая третья сделка — в цеху: инфопанель считает по ним нормативы.
            if ($i % 3 === 0) {
                Deal::create([
                    'title' => "Наряд: Сделка {$i}",
                    'client_name' => $deal->client_name,
                    'client_phone' => $deal->client_phone,
                    'total_price' => $deal->total_price,
                    'due_date' => $deal->due_date,
                    'status_id' => DealStatus::InProduction,
                    'pipeline_type' => PipelineType::Factory,
                    'parent_deal_id' => $deal->id,
                    'manager_id' => $deal->manager_id,
                    'current_stage_id' => $factory?->id,
                ]);
            }
        }

        $sample = MaterialStock::query()->firstOrFail();

        for ($i = 0; $i < $materials; $i++) {
            $copy = $sample->replicate();
            $copy->name = "Материал {$i}";
            $copy->sku = "MAT-{$i}";
            $copy->save();
        }
    }
}
