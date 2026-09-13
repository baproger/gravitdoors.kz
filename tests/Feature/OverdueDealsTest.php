<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Filament\Pages\OverdueDeals;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use Database\Seeders\DoorOptionSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Просрочка: красным везде, самая большая — сверху, срок сдачи обязателен. */
class OverdueDealsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, DoorOptionSeeder::class]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]));
    }

    public function test_most_overdue_deal_comes_first(): void
    {
        $ten = $this->deal('Десять дней', now()->subDays(10));
        $two = $this->deal('Два дня', now()->subDays(2));
        $this->deal('В срок', now()->addDays(5));
        $this->deal('Закрытая просроченная', now()->subDays(30), DealStatus::Completed);

        Livewire::test(OverdueDeals::class)
            ->assertCanSeeTableRecords([$ten, $two], inOrder: true)
            ->assertCountTableRecords(2)
            ->assertSee('10 дней')
            ->assertSee('2 дня');
    }

    public function test_navigation_badge_counts_open_overdue_only(): void
    {
        $this->deal('А', now()->subDay());
        $this->deal('Б', now()->subDays(3));
        $this->deal('Закрытая', now()->subDays(3), DealStatus::Cancelled);

        $this->assertSame('2', OverdueDeals::getNavigationBadge());
    }

    public function test_stuck_on_stage_tab_lists_deals_over_the_stage_norm(): void
    {
        // Норматив «Замер и расчёт» — 24 ч; сделка стоит 30 ч.
        $stuck = $this->deal('Застряла', now()->addDays(5));
        $stuck->forceFill(['current_stage_id' => $this->stage('measurement')->id, 'stage_entered_at' => now()->subHours(30)])->saveQuietly();

        $fresh = $this->deal('Свежая', now()->addDays(5));
        $fresh->forceFill(['current_stage_id' => $this->stage('measurement')->id, 'stage_entered_at' => now()->subHours(2)])->saveQuietly();

        $this->assertTrue($stuck->refresh()->isStageOverdue());
        $this->assertFalse($fresh->refresh()->isStageOverdue());

        Livewire::test(OverdueDeals::class)
            ->set('mode', 'stage')
            ->assertCanSeeTableRecords([$stuck])
            ->assertCanNotSeeTableRecords([$fresh]);
    }

    public function test_overdue_is_red_on_kanban_and_in_the_card(): void
    {
        $deal = $this->deal('Горит', now()->subDays(4));

        $this->get('/admin/kanban/sales')->assertOk()->assertSee('gravit-card--overdue')->assertSee('просрочено 4 дн.');
        $this->get("/admin/deals/{$deal->id}/edit")->assertOk()->assertSee('просрочка 4 дня');
    }

    public function test_due_date_is_required_for_a_new_deal(): void
    {
        Livewire::test(CreateDeal::class)
            ->fillForm(['title' => 'Без срока', 'client_name' => 'Клиент', 'client_phone' => '+7 (707) 111-22-33', 'due_date' => null])
            ->call('create')
            ->assertHasFormErrors(['due_date']);
    }

    public function test_workshop_sees_only_its_overdue_orders(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Worker->value]));
        $this->deal('Продажи', now()->subDays(3));

        Livewire::test(OverdueDeals::class)->assertCountTableRecords(0);
        $this->assertNull(OverdueDeals::getNavigationBadge());
    }

    private function deal(string $title, $due, DealStatus $status = DealStatus::InWork): Deal
    {
        return Deal::create([
            'title' => $title,
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'status_id' => $status,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => $this->stage('contract')->id,
            'due_date' => $due,
        ]);
    }

    private function stage(string $code): FactoryStage
    {
        return FactoryStage::query()->ofPipeline(PipelineType::Sales)->where('code', $code)->firstOrFail();
    }
}
