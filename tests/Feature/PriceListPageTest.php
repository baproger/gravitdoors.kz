<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\DoorOptions\Pages\PriceList;
use App\Models\Deal;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Страница прайса bento-сеткой: права, поиск и правки через окна. */
class PriceListPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_page_shows_a_card_for_every_group(): void
    {
        $this->get('/admin/door-options')
            ->assertOk()
            ->assertSee('Толщина металла')
            ->assertSee('Замковая система')
            ->assertSee('Доп. опции')
            ->assertSee('Металл 1.5 мм')
            ->assertSee('Новая позиция');
    }

    public function test_manager_sees_the_price_list_but_cannot_change_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Manager->value]));

        $this->get('/admin/door-options')
            ->assertOk()
            ->assertSee('Металл 1.5 мм')
            ->assertDontSee('Новая позиция');

        Livewire::test(PriceList::class)
            ->call('toggleActive', $this->option('metal_1_5')->id)
            ->assertForbidden();
    }

    public function test_workshop_cannot_open_the_price_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Worker->value]));

        $this->get('/admin/door-options')->assertForbidden();
    }

    public function test_search_narrows_the_cards(): void
    {
        Livewire::test(PriceList::class)
            ->set('search', 'mottura')
            ->assertSee('Mottura')
            ->assertDontSee('Металл 1.5 мм');
    }

    public function test_option_is_created_in_the_group_it_was_added_from(): void
    {
        Livewire::test(PriceList::class)
            ->callAction('createOption', data: [
                'label' => 'Замок Cisa',
                'price' => 52_000,
                'price_type' => 'fixed',
                'is_active' => true,
            ], arguments: ['category' => 'lock_system'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('door_options', ['label' => 'Замок Cisa', 'category' => 'lock_system']);
    }

    public function test_edit_changes_the_price_but_never_the_code(): void
    {
        $option = $this->option('metal_1_5');

        Livewire::test(PriceList::class)
            ->callAction('editOption', data: ['price' => 13_000, 'code' => 'hacked'], arguments: ['option' => $option->id])
            ->assertHasNoActionErrors();

        $option->refresh();
        $this->assertSame('metal_1_5', $option->code);
        $this->assertEqualsWithDelta(13_000, (float) $option->price, 0.01);
    }

    public function test_option_used_in_an_order_survives_the_delete_button(): void
    {
        $deal = Deal::create([
            'title' => 'Сделка с замком',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', 'contract')->value('id'),
        ]);
        DoorConfiguration::create([
            'deal_id' => $deal->id, 'category' => 'comfort', 'model' => 'agora',
            'height' => 2050, 'width' => 950, 'opening_side' => 'right', 'quantity' => 1,
            'metal_thickness' => 'metal_1_5', 'lock_system' => 'lock_kale',
        ]);

        Livewire::test(PriceList::class)
            ->callAction('deleteOption', arguments: ['option' => $this->option('lock_kale')->id]);

        $this->assertDatabaseHas('door_options', ['code' => 'lock_kale']);
    }

    public function test_star_makes_the_single_default(): void
    {
        Livewire::test(PriceList::class)->call('toggleDefault', $this->option('lock_border')->id);

        $this->assertTrue($this->option('lock_border')->is_default);
        $this->assertFalse($this->option('lock_kale')->is_default);
    }

    private function option(string $code): DoorOption
    {
        return DoorOption::query()->where('code', $code)->firstOrFail();
    }
}
