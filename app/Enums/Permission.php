<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Что можно разрешать ролям.
 *
 * Первая группа ключей повторяет пункты меню один к одному: раздел сайдбара —
 * это право с тем же ключом. Вторая (`Действия`) — полномочия внутри экранов,
 * у которых нет своего пункта меню: удаление сделки, отметка оплаты, суммы.
 *
 * Значения по умолчанию здесь — рекомендованная матрица из ТЗ. В базе
 * (`role_permissions`) хранятся только отличия от неё, поэтому свежая
 * установка работает без настройки, а «сбросить роль» — это удалить строки.
 */
enum Permission: string implements HasLabel
{
    // --- Работа -------------------------------------------------------------
    case WorkSalesKanban = 'work.sales_kanban';
    case WorkFactoryKanban = 'work.factory_kanban';
    case WorkOverdue = 'work.overdue';
    case WorkDeals = 'work.deals';
    case WorkMaterials = 'work.materials';
    case WorkStockMovements = 'work.stock_movements';
    case WorkTenders = 'work.tenders';

    // --- Финансы ------------------------------------------------------------
    case FinanceMySalary = 'finance.my_salary';
    case FinanceOverview = 'finance.overview';
    case FinanceInvoices = 'finance.invoices';
    case FinanceIncomes = 'finance.incomes';
    case FinanceExpenses = 'finance.expenses';
    case FinanceCash = 'finance.cash';
    case FinanceDebts = 'finance.debts';
    case FinancePayrollShop = 'finance.payroll_shop';
    case FinanceSalarySheets = 'finance.salary_sheets';
    case FinanceBonuses = 'finance.bonuses';

    // --- Настройки ----------------------------------------------------------
    case SettingsStages = 'settings.stages';
    case SettingsPrice = 'settings.price';
    case SettingsEmployees = 'settings.employees';
    case SettingsWorkshop = 'settings.workshop';
    case SettingsFinance = 'settings.finance';
    case SettingsCompany = 'settings.company';
    case SettingsCatalogs = 'settings.catalogs';
    case SettingsAccess = 'settings.access';

    // --- Действия внутри экранов -------------------------------------------
    case DealsDelete = 'deals.delete';
    case DealsCancel = 'deals.cancel';
    case DealsPaymentFlag = 'deals.payment_flag';
    case KanbanTotals = 'kanban.totals';
    case KanbanMoney = 'kanban.money';
    case FinanceApprove = 'finance.approve';
    case EmployeesFinance = 'employees.finance';
    case FactoryMaterials = 'factory.materials';

    public function getLabel(): string
    {
        return match ($this) {
            self::WorkSalesKanban => 'Воронка продаж',
            self::WorkFactoryKanban => 'Воронка завода',
            self::WorkOverdue => 'Просроченные',
            self::WorkDeals => 'Сделки и наряды',
            self::WorkMaterials => 'Склад материалов',
            self::WorkStockMovements => 'Движения склада',
            self::WorkTenders => 'Тендеры',

            self::FinanceMySalary => 'Моя зарплата',
            self::FinanceOverview => 'Финансы — обзор',
            self::FinanceInvoices => 'Счета',
            self::FinanceIncomes => 'Поступления',
            self::FinanceExpenses => 'Расходы',
            self::FinanceCash => 'Касса и банк',
            self::FinanceDebts => 'Задолженности',
            self::FinancePayrollShop => 'Зарплата цеха',
            self::FinanceSalarySheets => 'Зарплата — ведомость',
            self::FinanceBonuses => 'Бонусы',

            self::SettingsStages => 'Этапы воронок',
            self::SettingsPrice => 'Прайс конфигуратора',
            self::SettingsEmployees => 'Сотрудники',
            self::SettingsWorkshop => 'Экран цеха',
            self::SettingsFinance => 'Настройки финансов',
            self::SettingsCompany => 'Компания и реквизиты',
            self::SettingsCatalogs => 'Справочники',
            self::SettingsAccess => 'Роли и доступы',

            self::DealsDelete => 'Удаление сделок',
            self::DealsCancel => 'Отказ и отмена сделки',
            self::DealsPaymentFlag => 'Отметка оплаты и блокировка отгрузки',
            self::KanbanTotals => 'Сводные суммы воронки',
            self::KanbanMoney => 'Суммы в карточках и списках',
            self::FinanceApprove => 'Подтверждение расходов, бонусов и выплат',
            self::EmployeesFinance => 'Оклады, ставки и бонусный процент',
            self::FactoryMaterials => 'Отметка фактических материалов',
        };
    }

    public function hint(): ?string
    {
        return match ($this) {
            self::WorkDeals => 'Список сделок и нарядов, карточка сделки',
            self::WorkTenders => 'Тендеры, лоты и документы заявки; из выигранного лота — сделка',
            self::FinanceMySalary => 'Своя зарплата — чужие цифры недоступны на любом уровне',
            self::SettingsEmployees => 'Карточки сотрудников без финансовой части',
            self::EmployeesFinance => 'Секция «Условия работы» в карточке сотрудника',
            self::DealsCancel => '«Только свои» — менеджер отменяет лишь свою сделку',
            self::KanbanTotals => 'Итог по колонке канбана и сводные плитки',
            self::KanbanMoney => 'Стоимость на карточках, в списках и в карточке сделки',
            self::DealsPaymentFlag => 'Запрет отгрузки, пока клиент не рассчитался',
            self::FinanceApprove => 'Нужно вместе с доступом к самому разделу: сняв его, оставите проверку за директором',
            self::FactoryMaterials => 'Факт расхода материалов по наряду',
            default => null,
        };
    }

    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'work.') => 'Работа',
            str_starts_with($this->value, 'finance.') => 'Финансы',
            str_starts_with($this->value, 'settings.') => 'Настройки',
            default => 'Действия внутри экранов',
        };
    }

    /** Право — это пункт меню (а не полномочие внутри экрана). */
    public function isNavigation(): bool
    {
        return $this->group() !== 'Действия внутри экранов';
    }

    /**
     * Уровни, которые имеют смысл для этого права.
     *
     * «Только свои» предлагается лишь там, где у записи есть владелец:
     * у склада или настроек «свои» не бывает.
     *
     * @return list<AccessLevel>
     */
    public function levels(): array
    {
        return match ($this) {
            self::WorkSalesKanban, self::WorkOverdue, self::WorkDeals, self::WorkTenders => [
                AccessLevel::None, AccessLevel::Read, AccessLevel::Own, AccessLevel::Full,
            ],
            self::DealsCancel, self::KanbanMoney => [
                AccessLevel::None, AccessLevel::Own, AccessLevel::Full,
            ],
            self::FinanceMySalary, self::SettingsAccess, self::DealsDelete,
            self::DealsPaymentFlag, self::KanbanTotals, self::FactoryMaterials, self::FinanceApprove => [
                AccessLevel::None, AccessLevel::Full,
            ],
            default => [AccessLevel::None, AccessLevel::Read, AccessLevel::Full],
        };
    }

    public function supports(AccessLevel $level): bool
    {
        return in_array($level, $this->levels(), true);
    }

    /**
     * Рекомендованный уровень для роли.
     *
     * Придуманные в панели роли здесь не перечислены и начинаются с «Нет»:
     * права им проставляются копированием с существующей роли.
     */
    public function default(string $role): AccessLevel
    {
        return $this->defaults()[$role] ?? AccessLevel::None;
    }

    /**
     * Матрица из ТЗ. Директор не перечисляется: у него всегда полный доступ,
     * иначе настройками можно было бы запереть единственного хозяина системы.
     *
     * @return array<string, AccessLevel>
     */
    private function defaults(): array
    {
        $own = AccessLevel::Own;
        $read = AccessLevel::Read;
        $full = AccessLevel::Full;

        return match ($this) {
            // B2B ведёт юрлиц как менеджер розницу — свои сделки в той же воронке.
            self::WorkSalesKanban => ['manager' => $own, 'b2b' => $own, 'accountant' => $read],
            self::WorkFactoryKanban => ['manager' => $read, 'b2b' => $read, 'accountant' => $read, 'hr' => $read, 'master' => $full, 'worker' => $read],
            self::WorkOverdue => ['manager' => $own, 'b2b' => $own, 'accountant' => $read, 'hr' => $read, 'master' => $full],
            self::WorkDeals => ['manager' => $own, 'b2b' => $own, 'accountant' => $full, 'master' => $read, 'worker' => $read],
            self::WorkMaterials => ['manager' => $read, 'b2b' => $read, 'accountant' => $full, 'master' => $full],
            self::WorkStockMovements => ['manager' => $read, 'b2b' => $read, 'accountant' => $full, 'master' => $full],
            // Тендеры — работа B2B. Бухгалтер смотрит: обеспечение заявки — это деньги.
            self::WorkTenders => ['b2b' => $own, 'accountant' => $read],

            // Своя зарплата — у всех: страница показывает только собственные цифры.
            self::FinanceMySalary => [
                'manager' => $full, 'b2b' => $full, 'accountant' => $full, 'hr' => $full,
                'surveyor' => $full, 'master' => $full, 'worker' => $full,
            ],
            self::FinanceOverview, self::FinanceInvoices, self::FinanceIncomes,
            self::FinanceExpenses, self::FinanceCash, self::FinanceDebts => ['accountant' => $full],
            self::FinancePayrollShop, self::FinanceSalarySheets, self::FinanceBonuses => ['accountant' => $full, 'hr' => $full],

            self::SettingsEmployees => ['accountant' => $read, 'hr' => $full],
            self::SettingsWorkshop => ['master' => $full],
            self::SettingsFinance => ['accountant' => $full],
            self::SettingsCompany => ['accountant' => $read],
            self::SettingsStages, self::SettingsPrice, self::SettingsCatalogs, self::SettingsAccess => [],

            self::DealsDelete => [],
            self::DealsCancel => ['manager' => $own, 'b2b' => $own],
            self::DealsPaymentFlag => ['accountant' => $full],
            self::KanbanTotals => ['accountant' => $full],
            self::KanbanMoney => ['manager' => $own, 'b2b' => $own, 'accountant' => $full],
            self::FinanceApprove => ['accountant' => $full, 'hr' => $full],
            self::EmployeesFinance => ['accountant' => $full, 'hr' => $full],
            self::FactoryMaterials => ['master' => $full, 'worker' => $full],
        };
    }

    /** @return list<self> */
    public static function ofGroup(string $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $p): bool => $p->group() === $group));
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return ['Работа', 'Финансы', 'Настройки', 'Действия внутри экранов'];
    }
}
