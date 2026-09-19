<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Справочники для боевого сервера: без демо-сделок и демо-сотрудников.
 *
 * Этапы обеих воронок, склад, касса и банк, прайс конфигуратора — то, без чего
 * система пустая и в неё нельзя завести первую сделку. Директора создаёт
 * `gravit:install`: пароль в сидере — это пароль в git.
 *
 * Запускать только один раз, при установке: сидеры перезаписывают названия
 * этапов и цены прайса, а их владелец потом правит в панели. `gravit:install`
 * сам проверяет, что база пустая.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FactoryStageSeeder::class,
            MaterialStockSeeder::class,
            CashAccountSeeder::class,
            DoorOptionSeeder::class,
            // Последним: боевая цепочка цеха из 13 этапов поверх базовой.
            GravitFactoryStagesSeeder::class,
        ]);
    }
}
