<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\FactoryStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Новая сделка» видна с любой страницы тому, кто заводит сделки, и не
 * показывается тем, кому воронка продаж открыта только на чтение.
 */
class NewDealButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_director_and_manager_see_the_button_everywhere(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));

            foreach (['/admin', '/admin/kanban/sales', '/admin/deals', '/admin/clients', '/admin/material-stocks'] as $url) {
                $this->get($url)
                    ->assertOk()
                    ->assertSee('Новая сделка')
                    ->assertSee('/admin/deals/create');
            }
        }
    }

    public function test_readers_and_the_factory_do_not_get_the_button(): void
    {
        foreach ([UserRole::Accountant, UserRole::Master, UserRole::Worker, UserRole::Hr] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role->value]));

            $this->get('/admin')->assertOk()->assertDontSee('/admin/deals/create');
        }
    }
}
