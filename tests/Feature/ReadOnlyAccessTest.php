<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\MaterialStocks\Pages\ManageMaterialStocks;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\MaterialStock;
use App\Models\User;
use App\Services\AccessControl;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Уровень «чтение»: видно всё, менять нельзя ничего. */
class ReadOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class]);

        $this->accountant = User::factory()->create(['role' => UserRole::Accountant->value]);
        $this->deal = Deal::create([
            'title' => 'Сделка на сверку',
            'client_name' => 'Клиент',
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
            'manager_id' => User::factory()->create(['role' => UserRole::Manager->value])->id,
            'total_price' => 700_000,
            'due_date' => now()->addWeek(),
        ]);

        $this->actingAs($this->accountant);
    }

    public function test_reader_opens_the_card_for_viewing_but_not_for_editing(): void
    {
        $this->assertTrue($this->accountant->can('view', $this->deal));
        $this->assertFalse($this->accountant->can('update', $this->deal));

        $this->get(DealResource::getUrl('view', ['record' => $this->deal]))
            ->assertOk()
            ->assertSee('Сделка на сверку')
            ->assertSee('изменения недоступны');

        $this->get(DealResource::getUrl('edit', ['record' => $this->deal]))->assertForbidden();
    }

    public function test_links_lead_readers_to_the_view_page_and_editors_to_the_form(): void
    {
        $this->assertStringEndsWith((string) $this->deal->id, DealResource::cardUrl($this->deal));
        $this->assertStringNotContainsString('/edit', DealResource::cardUrl($this->deal));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
        $this->assertStringEndsWith('/edit', DealResource::cardUrl($this->deal));
    }

    public function test_reader_cannot_create_or_move_deals(): void
    {
        $this->assertFalse(DealResource::canCreate());
        $this->assertFalse($this->accountant->can('move', $this->deal));
        $this->assertFalse($this->accountant->can('delete', $this->deal));

        $this->get('/admin/kanban/sales')
            ->assertOk()
            ->assertSee('Сделка на сверку')
            ->assertDontSee('wire:click="moveDeal(', false);
    }

    public function test_warehouse_is_read_only_for_the_sales_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager->value]);
        $material = MaterialStock::query()->firstOrFail();

        $this->actingAs($manager);

        $this->get('/admin/material-stocks')->assertOk()->assertSee($material->name);
        $this->assertFalse($manager->can('update', $material));
        $this->assertFalse($manager->can('create', MaterialStock::class));

        Livewire::test(ManageMaterialStocks::class)
            ->assertActionHidden(TestAction::make('receipt')->table($material))
            ->assertActionHidden(TestAction::make('edit')->table($material));
    }

    public function test_reader_still_sees_money_when_the_right_allows(): void
    {
        // У бухгалтера суммы открыты, у кадров — нет.
        $this->get('/admin/deals')->assertOk()->assertSee('700 000');

        $this->actingAs(User::factory()->create(['role' => UserRole::Hr->value]));
        $this->get('/admin/deals')->assertForbidden();
    }
}
