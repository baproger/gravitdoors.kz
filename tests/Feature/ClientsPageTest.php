<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\Clients;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * База клиентов растёт из сделок: закрытая сделка не исчезает, а становится
 * историей клиента, из которой заводится повторный заказ.
 */
class ClientsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->actingAs($this->manager);
    }

    public function test_clients_are_deals_grouped_by_phone_with_totals(): void
    {
        $this->deal(['client_phone' => '+7 (700) 111-11-11', 'client_name' => 'Асель', 'total_price' => 300_000, 'prepayment' => 300_000, 'status_id' => DealStatus::Completed, 'created_at' => now()->subMonths(2)]);
        $this->deal(['client_phone' => '+7 (700) 111-11-11', 'client_name' => 'Асель Ким', 'total_price' => 200_000, 'prepayment' => 50_000, 'city' => 'Астана']);
        $this->deal(['client_phone' => '+7 (700) 222-22-22', 'client_name' => 'Болат', 'total_price' => 100_000]);

        $component = Livewire::test(Clients::class);
        $records = collect($component->instance()->getTableRecords()->items());

        $this->assertCount(2, $records);

        $asel = $records->firstWhere('client_phone', '+7 (700) 111-11-11');
        $this->assertSame('Асель Ким', $asel['title'], 'имя — из последней сделки');
        $this->assertSame('Астана', $asel['city']);
        $this->assertSame(2, $asel['deals_count']);
        $this->assertSame(1, $asel['open_count']);
        $this->assertEqualsWithDelta(500_000, $asel['total_sum'], 0.01);
        $this->assertEqualsWithDelta(150_000, $asel['due_sum'], 0.01);

        $component->assertSee('Асель Ким')->assertSee('Болат');
    }

    public function test_company_client_shows_company_and_bin(): void
    {
        $this->deal(['client_type' => ClientType::Company, 'client_company' => 'ТОО «Стройдом»', 'client_bin' => '123456789012', 'client_name' => 'Ерлан']);

        Livewire::test(Clients::class)
            ->assertSee('ТОО «Стройдом»')
            ->assertSee('БИН 123456789012');
    }

    public function test_search_finds_by_name_phone_company_and_bin(): void
    {
        $this->deal(['client_phone' => '+7 (700) 111-11-11', 'client_name' => 'Асель']);
        $this->deal(['client_phone' => '+7 (700) 222-22-22', 'client_name' => 'Болат', 'client_company' => 'ТОО «Ромашка»', 'client_bin' => '999000111222', 'client_type' => ClientType::Company]);

        foreach (['Болат', '222-22', 'Ромашка', '999000'] as $needle) {
            Livewire::test(Clients::class)
                ->searchTable($needle)
                ->assertSee('Болат')
                ->assertDontSee('Асель');
        }
    }

    public function test_manager_sees_only_own_clients(): void
    {
        $other = User::factory()->create(['role' => UserRole::Manager->value]);
        $this->deal(['client_name' => 'Мой клиент']);
        $this->deal(['client_name' => 'Чужой клиент', 'client_phone' => '+7 (700) 999-99-99', 'manager_id' => $other->id]);

        Livewire::test(Clients::class)->assertSee('Мой клиент')->assertDontSee('Чужой клиент');
    }

    public function test_worker_has_no_clients_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Surveyor->value]));

        $this->get('/admin/clients')->assertForbidden();
    }

    public function test_new_deal_from_a_client_is_prefilled_from_the_last_deal(): void
    {
        $this->deal(['client_name' => 'Старое имя', 'client_phone' => '+7 (700) 111-11-11', 'created_at' => now()->subYear()]);
        $this->deal([
            'client_name' => 'Асель Ким', 'client_phone' => '+7 (700) 111-11-11', 'client_phone_extra' => '+7 (701) 000-00-01',
            'client_email' => 'asel@example.kz', 'city' => 'Астана', 'client_address' => 'пр. Кабанбай батыра, 10',
        ]);

        $this->get('/admin/deals/create?client='.urlencode('+7 (700) 111-11-11'))->assertOk();

        Livewire::withQueryParams(['client' => '+7 (700) 111-11-11'])
            ->test(CreateDeal::class)
            ->assertFormSet([
                'client_name' => 'Асель Ким',
                'client_phone' => '+7 (700) 111-11-11',
                'client_phone_extra' => '+7 (701) 000-00-01',
                'client_email' => 'asel@example.kz',
                'city' => 'Астана',
                'client_address' => 'пр. Кабанбай батыра, 10',
            ]);
    }

    public function test_closed_deals_have_their_own_tab(): void
    {
        $this->deal(['title' => 'Открытая сделка']);
        $this->deal(['title' => 'Завершённая сделка', 'status_id' => DealStatus::Completed]);
        $this->deal(['title' => 'Отменённая сделка', 'status_id' => DealStatus::Cancelled]);

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'closed')
            ->assertSee('Завершённая сделка')
            ->assertSee('Отменённая сделка')
            ->assertDontSee('Открытая сделка');

        Livewire::test(ListDeals::class)
            ->set('activeTab', 'sales')
            ->assertSee('Открытая сделка')
            ->assertDontSee('Завершённая сделка');
    }

    /** @param array<string, mixed> $attributes */
    private function deal(array $attributes = []): Deal
    {
        return Deal::create(array_merge([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 100_000,
            'due_date' => now()->addWeeks(3),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'manager_id' => $this->manager->id,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ], $attributes));
    }
}
