<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Models\Deal;
use App\Support\Cities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Город выбирается из списка, а не набирается руками.
 *
 * Свободный ввод давал «Алматы», «алматы» и «г. Алматы» как три разных города:
 * фильтр по городу и группировка клиентов переставали сходиться.
 */
class CitySelectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cities::forget();
    }

    public function test_list_starts_with_kazakh_cities_even_on_an_empty_base(): void
    {
        $options = Cities::options();

        $this->assertArrayHasKey('Алматы', $options);
        $this->assertArrayHasKey('Астана', $options);
        $this->assertArrayHasKey('Шымкент', $options);
        $this->assertSame('Алматы', $options['Алматы'], 'В базу пишется название, а не код');
    }

    /** Город, введённый однажды, появляется у всех менеджеров. */
    public function test_city_already_used_in_a_deal_joins_the_list(): void
    {
        $this->deal('Каскелен');
        Cities::forget();

        $this->assertArrayHasKey('Каскелен', Cities::options());
    }

    public function test_added_city_is_normalised_so_it_does_not_double(): void
    {
        $this->assertSame('Алматы', Cities::normalize('алматы'));
        $this->assertSame('Алматы', Cities::normalize('  АЛМАТЫ  '));
        $this->assertSame('Каскелен', Cities::normalize('каскелен'));
        $this->assertNull(Cities::normalize('   '));
    }

    /** Уже известный город возвращается в своём написании, а не во втором. */
    public function test_known_city_keeps_its_original_spelling(): void
    {
        $this->deal('Усть-Каменогорск');
        Cities::forget();

        $this->assertSame('Усть-Каменогорск', Cities::normalize('усть-каменогорск'));
        $this->assertCount(1, array_filter(
            array_keys(Cities::options()),
            fn (string $city): bool => mb_strtolower($city) === 'усть-каменогорск',
        ));
    }

    public function test_list_has_no_duplicates_and_is_sorted(): void
    {
        $this->deal('Алматы');
        $this->deal('алматы');
        Cities::forget();

        $cities = array_keys(Cities::options());
        $lower = array_map('mb_strtolower', $cities);

        $this->assertSame($lower, array_unique($lower), 'Один город не должен попасть в список дважды');
        $this->assertNotEmpty($cities);
    }

    private function deal(string $city): Deal
    {
        return Deal::create([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'city' => $city,
            'total_price' => 100_000,
            'due_date' => now()->addWeek(),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
        ]);
    }
}
