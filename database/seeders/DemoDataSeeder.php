<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ClientType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\DoorCategory;
use App\Enums\DoorModel;
use App\Enums\OpeningSide;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\FactoryStage;
use App\Models\User;
use App\Services\DoorProductionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** Демонстрационные сделки: по одной в каждой колонке обеих воронок. */
class DemoDataSeeder extends Seeder
{
    /**
     * Заглушка подписанного договора: без файла сделку не пустят на этап
     * передачи в производство — так задан регламент воронки.
     */
    private function demoContract(string $number): string
    {
        $path = "deals/{$number}.txt";

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, "Договор {$number} (демонстрационный файл)");
        }

        return $path;
    }

    private function demoReceipt(string $number): string
    {
        $path = "receipts/{$number}.txt";

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, "Чек об оплате по сделке {$number} (демонстрационный файл)");
        }

        return $path;
    }

    public function run(DoorProductionService $production): void
    {
        $manager = User::firstOrCreate(
            ['email' => 'manager@gravit.kz'],
            [
                'name' => 'Айгуль Сериковна', 'password' => Hash::make('password'),
                'role' => UserRole::Manager->value, 'phone' => '+7 (701) 000-00-02',
                'salary' => 250_000, 'hired_at' => now()->subMonths(19), 'birth_date' => '1994-03-12',
            ],
        );

        User::firstOrCreate(
            ['email' => 'surveyor@gravit.kz'],
            [
                'name' => 'Бауыржан Замерщик', 'password' => Hash::make('password'),
                'role' => UserRole::Surveyor->value, 'phone' => '+7 (701) 000-00-04',
                'salary' => 180_000, 'hired_at' => now()->subMonths(7), 'birth_date' => '1999-07-21',
            ],
        );

        User::firstOrCreate(
            ['email' => 'accountant@gravit.kz'],
            [
                'name' => 'Гульнара Финансист', 'password' => Hash::make('password'),
                'role' => UserRole::Accountant->value, 'phone' => '+7 (701) 000-00-06',
                'salary' => 280_000, 'hired_at' => now()->subMonths(23), 'birth_date' => '1990-09-03',
            ],
        );

        User::firstOrCreate(
            ['email' => 'hr@gravit.kz'],
            [
                'name' => 'Динара Кадрова', 'password' => Hash::make('password'),
                'role' => UserRole::Hr->value, 'phone' => '+7 (701) 000-00-07',
                'salary' => 260_000, 'hired_at' => now()->subMonths(15), 'birth_date' => '1992-04-27',
            ],
        );

        User::firstOrCreate(
            ['email' => 'worker@gravit.kz'],
            [
                'name' => 'Даурен Сварщик', 'password' => Hash::make('password'),
                'role' => UserRole::Worker->value, 'phone' => '+7 (701) 000-00-05',
                'salary' => 160_000, 'hired_at' => now()->subMonths(11), 'birth_date' => '1996-02-18',
            ],
        );

        $master = User::firstOrCreate(
            ['email' => 'master@gravit.kz'],
            [
                'name' => 'Ерлан Мастер', 'password' => Hash::make('password'),
                'role' => UserRole::Master->value, 'phone' => '+7 (701) 000-00-03',
                'salary' => 220_000, 'hired_at' => now()->subMonths(31), 'birth_date' => '1988-11-05',
            ],
        );

        $stages = FactoryStage::query()->ofPipeline(PipelineType::Sales)->ordered()->get()->keyBy('code');

        $clients = [
            [
                'title' => 'ЖК «Алатау», кв. 45', 'name' => 'Асхат Жумабеков', 'phone' => '+7 (707) 111-22-33',
                'email' => 'ashat.zh@mail.kz', 'city' => 'Алматы', 'source' => DealSource::Instagram,
                'address' => 'ул. Розыбакиева 247, ЖК «Алатау», кв. 45',
                'stage' => 'new', 'status' => DealStatus::New, 'prepayment' => 0,
            ],
            [
                // Просроченная: чтобы «Просроченные» и красные метки было на чём показать.
                'title' => 'Частный дом, Каскелен', 'name' => 'Марина Ким', 'phone' => '+7 (705) 444-55-66', 'due' => now()->subDays(4),
                'email' => 'm.kim@gmail.com', 'city' => 'Каскелен', 'source' => DealSource::Recommendation,
                'address' => 'мкр. Алатау, ул. Абая 12',
                'stage' => 'measurement', 'status' => DealStatus::InWork, 'prepayment' => 0,
                'delivery' => 15_000, 'installation' => 25_000,
            ],
            [
                'title' => 'ЖК «Керемет», кв. 112', 'name' => 'Данияр Оспанов', 'phone' => '+7 (777) 888-99-00',
                'email' => 'd.ospanov@mail.ru', 'city' => 'Алматы', 'source' => DealSource::Site,
                'address' => 'ул. Сейфуллина 500, ЖК «Керемет», кв. 112',
                'stage' => 'contract', 'status' => DealStatus::InWork, 'prepayment' => 90_000,
                'contract' => 'ДГ-2026-014', 'delivery' => 15_000, 'installation' => 30_000,
            ],
            [
                'title' => 'Офис на Абая 150', 'name' => 'Ержан Тулегенов', 'phone' => '+7 (727) 355-66-77',
                'email' => 'info@stroyinvest.kz', 'city' => 'Алматы', 'source' => DealSource::Tender,
                'address' => 'пр. Абая 150, БЦ «Алмалы», 3 этаж',
                'company' => 'ТОО «Строй-Инвест»', 'bin' => '150340012345',
                'stage' => 'contract', 'status' => DealStatus::InWork, 'prepayment' => 150_000,
                'contract' => 'ДГ-2026-018', 'delivery' => 20_000, 'installation' => 45_000,
            ],
        ];

        foreach ($clients as $row) {
            $title = $row['title'];

            $deal = Deal::firstOrCreate(
                ['title' => $title],
                [
                    'client_name' => $row['name'],
                    'client_type' => isset($row['company']) ? ClientType::Company : ClientType::Individual,
                    'client_company' => $row['company'] ?? null,
                    'client_bin' => $row['bin'] ?? null,
                    'client_phone' => $row['phone'],
                    'client_email' => $row['email'],
                    'client_address' => $row['address'],
                    'city' => $row['city'],
                    'source' => $row['source'],
                    'contract_number' => $row['contract'] ?? null,
                    'contract_date' => isset($row['contract']) ? now()->subDays(random_int(3, 20)) : null,
                    'documents' => isset($row['contract']) ? [$this->demoContract($row['contract'])] : null,
                    'measured_at' => $row['stage'] === 'new' ? null : now()->subDays(random_int(2, 15)),
                    'delivery_cost' => $row['delivery'] ?? 0,
                    'installation_cost' => $row['installation'] ?? 0,
                    'status_id' => $row['status'],
                    'pipeline_type' => PipelineType::Sales,
                    'current_stage_id' => $stages[$row['stage']]->id,
                    'manager_id' => $manager->id,
                    'due_date' => $row['due'] ?? now()->addDays(random_int(7, 25)),
                ],
            );

            DoorConfiguration::firstOrCreate(
                ['deal_id' => $deal->id, 'position' => 1],
                [
                    'category' => DoorCategory::Premium,
                    'model' => DoorModel::Lion,
                    'height' => 2050,
                    'width' => random_int(0, 1) ? 950 : 860,
                    'opening_side' => random_int(0, 1) ? OpeningSide::Right->value : OpeningSide::Left->value,
                    'metal_thickness' => 'metal_1_5',
                    'outer_mdf_panel' => 'outer_mdf_16f',
                    'inner_mdf_panel' => 'inner_mdf_10',
                    'lock_system' => 'lock_kale_border',
                    'insulation_type' => 'ins_mineral',
                    'color_coating' => 'ral_powder',
                    'additional_options' => ['peephole', 'armor_plate'],
                    'quantity' => 1,
                ],
            );

            // У одной сделки — две двери: канбан и печатные формы должны
            // корректно вести себя не только на заказе из одной позиции.
            if ($title === 'Офис на Абая 150') {
                DoorConfiguration::firstOrCreate(
                    ['deal_id' => $deal->id, 'position' => 2],
                    [
                        'category' => DoorCategory::Comfort,
                        'model' => DoorModel::Agora,
                        'height' => 2050,
                        'width' => 1050,
                        'opening_side' => OpeningSide::Left->value,
                        'metal_thickness' => 'metal_1_2',
                        'outer_mdf_panel' => 'outer_mdf_10',
                        'inner_mdf_panel' => 'inner_none',
                        'lock_system' => 'lock_kale',
                        'insulation_type' => 'ins_eps',
                        'color_coating' => 'ral_powder',
                        'additional_options' => [],
                        'quantity' => 1,
                    ],
                );
            }

            // Предоплата — это платёж с чеком, а не число в карточке.
            if ($row['prepayment'] > 0 && $deal->payments()->doesntExist()) {
                $deal->payments()->create([
                    'amount' => $row['prepayment'],
                    'method' => PaymentMethod::Kaspi,
                    'paid_at' => now()->subDays(random_int(1, 5)),
                    'receipt_path' => $this->demoReceipt($deal->number),
                    'comment' => 'Предоплата по договору',
                ]);
            }

            $production->syncPricing($deal->refresh());
        }

        // Одна сделка проводится через автоматизацию целиком — чтобы на канбане
        // завода сразу были живые наряды на разных этапах.
        $inProduction = Deal::query()->sales()->where('title', 'ЖК «Керемет», кв. 112')->first();

        if ($inProduction && ! $inProduction->productionOrder()->exists()) {
            $production->moveToStage($inProduction, $stages['handed_to_production'], $manager);

            $order = $inProduction->productionOrder()->first();
            $production->startStage($order, $master);
            $production->completeCurrentStage($order, $master);
            $production->completeCurrentStage($order->refresh(), $master);
        }
    }
}
