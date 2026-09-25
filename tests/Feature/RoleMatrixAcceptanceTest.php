<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessControl;
use Database\Seeders\CashAccountSeeder;
use Database\Seeders\FactoryStageSeeder;
use Database\Seeders\MaterialStockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Приёмка матрицы: каждая роль открывает ровно свои разделы.
 *
 * Один тест на роль — если завтра кто-то поменяет право в коде, а не в
 * настройках, это всплывёт здесь, а не у пользователя.
 */
class RoleMatrixAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** Все пункты меню панели. */
    private const PAGES = [
        'sales_kanban' => '/admin/kanban/sales',
        'factory_kanban' => '/admin/kanban/factory',
        'overdue' => '/admin/overdue-deals',
        'deals' => '/admin/deals',
        'tenders' => '/admin/tenders',
        'materials' => '/admin/material-stocks',
        'stock_movements' => '/admin/stock-movements',
        'my_salary' => '/admin/my-salary',
        'finance' => '/admin/finance',
        'invoices' => '/admin/invoices',
        'incomes' => '/admin/incomes',
        'expenses' => '/admin/expenses',
        'cash' => '/admin/cash',
        'debts' => '/admin/debts',
        'payroll' => '/admin/payroll',
        'salary' => '/admin/salary',
        'bonuses' => '/admin/bonuses',
        'stages' => '/admin/factory-stages',
        'price' => '/admin/door-options',
        'workshop' => '/admin/workshop-access',
        'finance_settings' => '/admin/finance-settings',
        'access' => '/admin/access',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        AccessControl::flush();
        $this->seed([FactoryStageSeeder::class, MaterialStockSeeder::class, CashAccountSeeder::class]);
    }

    public function test_director_opens_every_section(): void
    {
        $this->assertSections(UserRole::Admin, array_keys(self::PAGES));
    }

    public function test_sales_manager_sees_work_and_own_salary_only(): void
    {
        $this->assertSections(UserRole::Manager, [
            'sales_kanban', 'factory_kanban', 'overdue', 'deals', 'materials', 'stock_movements', 'my_salary',
        ]);
    }

    public function test_b2b_manager_sees_sales_work_tenders_and_own_salary(): void
    {
        $this->assertSections(UserRole::B2b, [
            'sales_kanban', 'factory_kanban', 'overdue', 'deals', 'tenders', 'materials', 'stock_movements', 'my_salary',
        ]);
    }

    public function test_accountant_sees_all_money(): void
    {
        $this->assertSections(UserRole::Accountant, [
            'sales_kanban', 'factory_kanban', 'overdue', 'deals', 'tenders', 'materials', 'stock_movements',
            'my_salary', 'finance', 'invoices', 'incomes', 'expenses', 'cash', 'debts',
            'payroll', 'salary', 'bonuses', 'finance_settings',
        ]);
    }

    public function test_hr_sees_people_and_payroll_only(): void
    {
        $this->assertSections(UserRole::Hr, [
            'factory_kanban', 'overdue', 'my_salary', 'payroll', 'salary', 'bonuses',
        ]);
    }

    public function test_production_head_runs_the_workshop(): void
    {
        $this->assertSections(UserRole::Master, [
            'factory_kanban', 'overdue', 'deals', 'materials', 'stock_movements', 'my_salary', 'workshop',
        ]);
    }

    public function test_worker_only_sees_orders_and_own_salary(): void
    {
        $this->assertSections(UserRole::Worker, ['factory_kanban', 'deals', 'my_salary']);
    }

    public function test_surveyor_only_sees_own_salary(): void
    {
        $this->assertSections(UserRole::Surveyor, ['my_salary']);
    }

    /**
     * @param  list<string>  $allowed  ключи разделов, которые должны открываться
     */
    private function assertSections(UserRole $role, array $allowed): void
    {
        $this->actingAs(User::factory()->create(['role' => $role->value, 'is_active' => true]));

        foreach (self::PAGES as $key => $url) {
            $response = $this->get($url);
            $shouldOpen = in_array($key, $allowed, true);

            $this->assertSame(
                $shouldOpen ? 200 : 403,
                $response->status(),
                "{$role->getLabel()} → {$url}: ожидали ".($shouldOpen ? 'доступ' : '403'),
            );
        }
    }
}
