# Роли, права и настройки сайта — план реализации (для следующей модели)

> Две связанные задачи владельца:
> 1. **Матрица ролей**: Менеджер продаж · Директор · Бухгалтер-финансист · HR-директор · Сотрудник производства (+ рабочий и замерщик, уже есть).
> 2. **Всё, что внутри сайта, редактируется из настроек сайта** — права, справочники, реквизиты, тексты, коэффициенты расчёта. Ничего из этого не должно требовать правки кода.
>
> Перед началом: прочитать `project.md` (суть и правила), `README.md` (экраны), `finance-plan.md` (незакрытые шаги 9–12 финансов). Стиль: русские комментарии «почему», PHP-enum'ы, правила на сервере, Filament v4 (`Filament\Schemas\*`), плитки `gravit-bento / gravit-tile`, деньги через `App\Support\Money::format`.

---

## 0. Общие правила для всех шагов

- **Единый источник истины по доступу — реестр прав** (`AccessControl`, шаг 2–3). После шага 5 в коде **не должно остаться** прямых сравнений с ролью (`$user->role === UserRole::Admin`, `seesMoney()`) — кроме самого реестра и его значений по умолчанию. Проверить: `grep -rn "UserRole::" app | grep -v Enums/UserRole | grep -v Access` должен давать только реестр, `Department::forRole()` и сидеры.
- **Администратор проходит любую политику** через `Gate::before` (`AppServiceProvider:33`). Поэтому правила «нельзя удалить/изменить» живут в моделях (`canBeDeleted()`) и в действиях — как уже сделано для `Deal`, `MaterialStock`, `Expense`, `Debt`, `Bonus`. Новые роли (бухгалтер, HR) **не должны** попадать под `Gate::before`.
- **Видимость ≠ защита.** У каждого действия таблицы — `->authorize(...)`, у каждой страницы — `canAccess()`, у каждого запроса — ограничение в `getEloquentQuery()` / `scopedQuery()`. Скрытая кнопка обязана иметь серверную проверку.
- **Кэш.** Матрица прав и настройки читаются на каждый запрос → `Cache::rememberForever` с точечным сбросом при сохранении (образец — `App\Models\Setting`).
- **Тесты.** На каждый шаг — `tests/Feature/<Шаг>Test.php`: доступ по ролям (что видно, что 403), правило на сервере, поведение UI. Плюс строка в `AdminPanelSmokeTest::panelPages()` для новых страниц. После шага: `php artisan test && vendor/bin/pint && vendor/bin/phpstan analyse` (базовый долг ~50 ошибок про `$this->record` в страницах Filament — не увеличивать).
- **Документация.** После каждого шага — абзац в `README.md` и строки в `project.md` (§4 роли, §6 правила, §7 экраны, §10 история).
- **Не ломать.** Воронки (`DoorProductionService`), платежи и финансовые сервисы не трогаем — только точки доступа. Внешние пакеты не добавляем.

---

# ЧАСТЬ A. Роли и права — ВЫПОЛНЕНА

> Файлы: `app/Enums/{Permission,AccessLevel,UserRole}.php`, `app/Services/AccessControl.php`,
> `app/Models/RolePermission.php`, миграции `create_role_permissions_table` и
> `add_shipment_block_to_deals`, `app/Filament/Pages/AccessMatrix.php` (+ blade и стили `am-*`),
> `app/Filament/Resources/Deals/Pages/ViewDeal.php`, `Deal::scopeVisibleTo()`,
> `DoorProductionService::{setShipmentBlock,adjustMaterials}`, все политики в `app/Policies`.
> Тесты: `AccessControlTest`, `AccessMatrixPageTest`, `RoleMatrixAcceptanceTest`, `OwnScopeTest`,
> `ReadOnlyAccessTest`, `ShipmentBlockTest`, `FactoryMaterialsTest`.
>
> Отличия от первоначального плана:
> - добавлено право `finance.approve` («Подтверждение расходов, бонусов и выплат»): без него
>   уровень «Полный» в разделе означал бы и ввод, и самопроверку одним человеком;
> - вместо «выключенной формы» для читателей сделана отдельная страница просмотра карточки;
> - сделка без ответственного остаётся видимой на уровне «только свои», чтобы не потеряться.

## Шаг 1. Новые роли — СДЕЛАНО

`app/Enums/UserRole.php` — добавить, не меняя значения существующих (в базе уже лежат строки):

| case | value | Ярлык | Кто это |
|---|---|---|---|
| `Admin` | `admin` | **Директор** | собственник, полный доступ (значение оставить `admin` — на нём завязан `Gate::before` и старые записи) |
| `Manager` | `manager` | Менеджер продаж | без изменений |
| `Accountant` | `accountant` | **Бухгалтер-финансист** | новый |
| `Hr` | `hr` | **HR-директор** | новый |
| `Master` | `master` | **Начальник производства** | переименовать ярлык (было «Мастер цеха») |
| `Worker` | `worker` | Рабочий цеха | без изменений |
| `Surveyor` | `surveyor` | Замерщик | без изменений |

Там же:
- `getColor()` — добавить цвета новым ролям.
- **Удалить** `seesMoney()` и `isFactoryStaff()` после шага 5 (их заменит реестр). До этого — пометить `@deprecated`.
- `Department::forRole()` (`app/Enums/Department.php:54`) — `Accountant`, `Hr` → `Department::Sales`? Нет: завести `Department::Finance` и `Department::Hr` с ярлыками «Финансы» и «Кадры», чтобы история сделки не врала об отделе.
- `database/seeders/DemoDataSeeder.php` — добавить `accountant@gravit.kz` и `hr@gravit.kz` (пароль `password`), как сделано для `worker@gravit.kz`. Обновить список доступов в `project.md` §2 и `README.md`.

## Шаг 2. Реестр прав — СДЕЛАНО

**`app/Enums/AccessLevel.php`** (string enum, `HasLabel`, `HasColor`):

| case | value | Ярлык | Смысл |
|---|---|---|---|
| `None` | `none` | Нет | пункт меню скрыт, прямой URL → 403 |
| `Read` | `read` | Чтение | видно, но без создания, правки, перетаскивания и действий |
| `Own` | `own` | Только свои | видно и можно править только записи, где `manager_id`/`user_id` = текущий |
| `Full` | `full` | Полный | без ограничений |

Порядок силы: `None < Read < Own < Full`. Метод `atLeast(self $other): bool`.

**`app/Enums/Permission.php`** (string enum) — плоский список ключей. Для каждого: `group()` (Работа / Финансы / Настройки / Действия), `getLabel()`, `levels(): array` (какие уровни имеют смысл), `default(UserRole $role): AccessLevel` (матрица ниже).

Пункты меню (ключ = раздел сайдбара):

```
work.sales_kanban      Воронка продаж            none read own full
work.factory_kanban    Воронка завода            none read full
work.overdue           Просроченные              none read own full
work.deals             Сделки и наряды           none read own full
work.materials         Склад материалов          none read full
work.stock_movements   Движения склада           none read full

finance.my_salary      Моя зарплата              none full
finance.overview       Финансы — обзор           none read full
finance.invoices       Счета                     none read full
finance.incomes        Поступления               none read full
finance.expenses       Расходы                   none read full
finance.cash           Касса и банк              none read full
finance.debts          Задолженности             none read full
finance.payroll_shop   Зарплата цеха             none read full
finance.salary_sheets  Зарплата — ведомость      none read full
finance.bonuses        Бонусы                    none read full

settings.stages        Этапы воронок             none read full
settings.price         Прайс конфигуратора       none read full
settings.employees     Сотрудники                none read full
settings.workshop      Экран цеха                none read full
settings.finance       Настройки финансов        none read full
settings.company       Компания и реквизиты      none read full
settings.catalogs      Справочники               none read full
settings.access        Роли и доступы            none full
```

Отдельные полномочия (не пункты меню, а разрешения внутри экранов):

```
deals.delete           Удаление сделок                       none full
deals.cancel           Отказ и отмена сделки                 none own full
deals.payment_flag     Отметка оплаты и блокировка отгрузки  none full
kanban.totals          Сводные суммы и аналитика воронки     none full
kanban.money           Суммы на карточках и в списках        none full
employees.finance      Оклады, ставки, бонусный %            none read full
factory.materials      Отметка фактических материалов        none full
```

## Шаг 3. Хранилище и сервис доступа — СДЕЛАНО

**Миграция `create_role_permissions_table`**: `role` (string 20), `permission` (string 40), `level` (string 10), `updated_by`, timestamps, `unique(role, permission)`.
В базе хранятся **только отличия от значений по умолчанию** — система работает сразу после установки, а «Сбросить к рекомендуемым» = удалить строки роли.

**`app/Services/AccessControl.php`**:

```php
level(UserRole $role, Permission $p): AccessLevel   // из кэша, иначе default
allows(?User $user, Permission $p, AccessLevel $min = AccessLevel::Read): bool
can(Permission $p, AccessLevel $min = ...): bool     // для auth()->user()
isOwnOnly(?User $user, Permission $p): bool          // level === Own
set(UserRole $role, Permission $p, AccessLevel $l, ?User $actor): void  // + сброс кэша + запись в журнал (шаг 16)
matrix(): array                                      // роль → право → уровень, для страницы настроек
reset(UserRole $role): void
```
Кэш — один ключ `access.matrix`, сбрасывается при любом `set`/`reset`.

**Фасад для удобства**: хелпер `can_access(Permission::WorkDeals, AccessLevel::Full)` в `app/Support/helpers.php` (подключить в `composer.json` → `autoload.files`), чтобы blade-шаблоны не тянули сервис руками.

## Шаг 4. Экран «Настройки → Роли и доступы» — СДЕЛАНО

`app/Filament/Pages/AccessMatrix.php` (`/admin/access`), `canAccess()` — только `settings.access` = Full (по умолчанию только Директор).

- Таблица-матрица: строки — права, сгруппированные по `Permission::group()`, колонки — роли. В ячейке `<select>` с уровнями, которые поддерживает право (`Permission::levels()`), сохранение по `wire:change` через `AccessControl::set()`.
- Подсветка ячейки, если значение отличается от рекомендованного; кнопка «Сбросить роль к рекомендуемым».
- Легенда снизу: что значит каждый уровень, и предупреждение «Директор всегда имеет полный доступ» (строка Директора — только чтение матрицы, менять нельзя: иначе можно запереть самого себя).
- Вёрстка — как «Этапы воронок» (`resources/views/filament/resources/factory-stages/pipeline-stages.blade.php`): та же плотность и классы `gp-*`.

**Правила сервера**: нельзя выключить `settings.access` у Директора; нельзя выдать уровень, которого нет в `levels()`; менять матрицу может только тот, у кого `settings.access` = Full.

## Шаг 5. Перевести весь код на реестр — СДЕЛАНО

Механическая, но обязательная часть. По каждому файлу заменить проверку роли на `AccessControl`:

| Где | Было | Стало |
|---|---|---|
| `Filament/Pages/*.php` → `canAccess()` | `auth()->user()?->role->seesMoney()` | `can_access(Permission::FinanceOverview)` и т. д. по таблице пунктов меню |
| `Resources/*/…Resource.php` → `canAccess()`/`canViewAny()` | роль | соответствующее право |
| `app/Policies/*.php` | `in_array($user->role, [...])` | `AccessControl::allows($user, Permission::X, AccessLevel::Full)` |
| `KanbanBoardPage::canSeeMoney()` | `seesMoney()` | `can_access(Permission::KanbanMoney)` |
| `DealResource::getEloquentQuery()` | `seesMoney()` → только наряды | шаг 6 (см. ниже) |
| `OverdueDeals::scopedQuery()` | `seesMoney()` | шаг 6 |
| `DealsTable`, `DealForm`, `Payroll`, `CashDesk`, `SalarySheets`, `WorkshopScreen`, `DailyCheck`, `DealObserver` | роли | права |

Проверка шага: `grep -rn "UserRole::" app | grep -vE "Enums/(UserRole|Permission|Department)|Services/AccessControl"` → пусто.

## Шаг 6. «Только свои» — СДЕЛАНО

Где это должно работать:

- **`DealResource::getEloquentQuery()`**: если `work.deals` = `Own` → `->where('manager_id', auth()->id())` (для нарядов — `whereHas('parentDeal', …)`); если `Read` → тот же список, но `canCreate()` / `EditAction` / действия недоступны; если роль видит только наряды (производство) — прежний `factoryOrders()`.
- **Канбан** (`DoorProductionService::board()`): добавить параметр `?int $ownerId` и применять его к фильтру колонок. `KanbanBoardPage` передаёт `auth()->id()`, когда уровень `Own`. Фильтр «менеджер» в шапке при `Own` скрывается.
- **`OverdueDeals::scopedQuery()`**: при `Own` — `->where('manager_id', auth()->id())`.
- **Суммы**: сумма по колонке канбана и итоговые плитки показываются только при `kanban.totals` = Full. При `Own` менеджер видит суммы **своих** карточек (право `kanban.money`), но не итог воронки.
- **`Invoices`, `Incomes`**: при `Own` — только сделки, где он менеджер.
- **`deals.cancel` = Own**: менеджер может отменить только свою сделку; удаление (`deals.delete`) ему недоступно — вместо удаления «Отказ» с обязательной причиной (действие `cancelDeal` в `EditDeal` уже есть, добавить обязательный выбор причины из справочника «Причины отказа», шаг 13).

## Шаг 7. Режим «Только чтение» — СДЕЛАНО

- **Канбан**: при `Read` карточки не перетаскиваются, стрелки «←→» и «Готово ✓» скрыты (в blade уже есть `$canMove` — свести к `AccessControl`), кнопка «Открыть» ведёт на карточку в режиме просмотра.
- **Карточка сделки**: при `Read` форма открывается с `disabled()` на всех полях (в `DealResource` — `canEdit()`), полоса этапов не кликается, шапка без «Отменить сделку» и «Удалить».
- **Списки**: при `Read` скрыты `CreateAction`, `EditAction`, `DeleteAction` и массовые действия (у всех уже есть `->authorize()`, достаточно завести их на права).
- **Склад**: при `Read` — без «Прихода», правки остатков и удаления.

## Шаг 8. Полномочия внутри Канбана по ролям — СДЕЛАНО

- **Бухгалтер** (`deals.payment_flag` = Full): на карточке сделки и в списке — действие «Отметить оплату» (уже есть приём оплаты) и **флаг блокировки отгрузки**: новое поле `deals.shipment_blocked` + причина. Пока флаг стоит, `DoorProductionService::guardTransition()` не пускает сделку на этап с `completes_production`/отгрузку — бросает `ProductionException::shipmentBlocked()`. Снимает флаг тот же, кто ставил (или Директор). На канбане — красная пилюля «Отгрузка заблокирована: долг».
- **HR** (`work.deals` = Read + `finance.salary_sheets` Full): на карточках видит автора, исполнителей и время на этапе (уже есть), но без сумм — выдаётся через `kanban.money` = None.
- **Производство** (`factory.materials` = Full): в карточке наряда — вкладка «Материалы» с фактическим списанием (кнопка «Отметить фактический расход»: корректировка `stock_movements` с комментарием и автором). Плановый расход уже считает `DoorProductionService::materialRequirements()` — показать план и факт рядом.
- **Менеджер**: `work.factory_kanban` = Read — видит, где его заказ в цеху, но не двигает.

## Шаг 9. Финансы и сотрудники по матрице — СДЕЛАНО

- **Бухгалтер** получает всё финансовое, включая подтверждение расходов и выплаты (сейчас это жёстко «только admin» в `ExpensePolicy::approve`, `DebtPolicy::pay`, `SalarySheetPolicy::approve/pay`) → перевести на права `finance.expenses`/`finance.debts`/`finance.salary_sheets` = Full.
- **HR** получает `finance.payroll_shop`, `finance.salary_sheets`, `finance.bonuses` = Full и `settings.employees` = Full, но `finance.overview/cash/invoices/...` = None. Проверить, что страница «Зарплата» не тянет запрещённые данные (она берёт только ведомости — ок).
- **Сотрудники**: `settings.employees` = Full у HR и Директора, `Read` у бухгалтера; финансовая часть карточки (оклад, бонусный %) — отдельное право `employees.finance` (у бухгалтера Full, у HR Full, у остальных None). В `UserForm` секция «Условия работы» уже отделена — привязать её `visible()` к этому праву.
- **Моя зарплата** (`finance.my_salary`) — Full у всех ролей: страница уже показывает только своё.

---

# ЧАСТЬ B. Всё редактируется из настроек сайта

## Шаг 10. Раздел «Настройки» и общая механика

Группа меню «Настройки» получает подпункты: Компания · Роли и доступы · Этапы воронок · Прайс конфигуратора · Справочники · Расчёт и производство · Финансы · Экран цеха · Сотрудники · Журнал настроек.

Механика — расширить существующий `App\Models\Setting` (ключ-значение + кэш, уже есть):
- `Setting::json(string $key, array $default): array` и `Setting::putJson()` — для групп настроек;
- типизированные обёртки в `app/Support/SiteSettings.php`: `company()`, `pricing()`, `production()`, `notifications()`, `texts()` — с значениями по умолчанию из текущего `config/gravit.php`, чтобы ничего не сломалось до первой правки;
- **правило**: код читает настройку только через `SiteSettings`, никогда напрямую из `config('gravit.*')` (после шага 12 конфиг остаётся только как источник значений по умолчанию).

## Шаг 11. Настройки → Компания и реквизиты

Страница `/admin/settings/company`, право `settings.company`.

Поля: название, юр. лицо, БИН/ИИН, адрес, телефоны (повторяемое поле), e-mail, сайт, логотип (загрузка, диск `public`), валюта (код и символ), часовой пояс, начало рабочего дня, реквизиты банка (для счетов).

Где применяется: шапка панели и логин (`AdminPanelProvider` → `brandName`, `brandLogo`), публичная страница `/track` (`resources/views/track/show.blade.php` — сейчас «Gravit» зашито), письма и уведомления, будущие печатные формы (счёт, наряд), `Money::format()` (символ валюты — сейчас `config('gravit.currency.symbol')`, ~20 мест → перевести на `SiteSettings::currencySymbol()`).

## Шаг 12. Настройки → Расчёт и производство

Страница `/admin/settings/pricing`, право `settings.finance` (или отдельное `settings.pricing`, если понадобится разделение).

Переносим из `config/gravit.php` в настройки (конфиг → значения по умолчанию):
- сборка (`assembly_cost`), наценка (`markup_percent`), округление (`round_to`);
- габариты по умолчанию и предельные (`default_*`, `min_*`, `max_*`) — их же использует валидация формы двери;
- списание материалов при передаче в цех (`write_off_materials`);
- разрешать отрицательный склад (`allow_negative_stock`) — **по умолчанию выключить** перед боем (сейчас `true`, отмечено в `project.md` §11);
- срок сдачи по умолчанию (сейчас в `DealForm` зашито `now()->addWeeks(3)`).

Читатели: `DoorPriceCalculator`, `DoorProductionService`, `DoorConfigurationSchema`. Каждое изменение цены — только на новые расчёты; уже сохранённые расшифровки в `door_configurations` не пересчитываются.

## Шаг 13. Настройки → Справочники (enum → таблицы)

Сейчас это PHP-enum'ы, и добавить «новый источник заявки» или «новую категорию расхода» без программиста нельзя. Перевести в таблицу `catalog_items`: `catalog` (ключ справочника), `code`, `name`, `color`, `icon`, `order`, `is_active`, `meta` (json), `is_system`.

Справочники к переносу: источники сделок (`DealSource`), линейки и модели дверей (`DoorCategory`, `DoorModel`), способы оплаты (`PaymentMethod`), категории расходов (`ExpenseCategory` — с флагом «нужен чек» в `meta`), категории долгов (`DebtCategory` — с привязкой к категории расхода), единицы измерения (`MaterialUnit`), **причины отказа** (новый, для шага 6), типы клиентов (`ClientType`).

**Правила целостности — как в прайсе (`PriceListService`), повторить их здесь:**
- код нельзя менять после создания (на него ссылаются старые записи);
- позицию, использованную хоть в одной записи, нельзя удалить — только снять с продажи (`is_active = false`): в старых записях она продолжает показываться и считаться;
- системные позиции (`is_system`, например способ оплаты «наличные», от которого зависит касса) нельзя удалить и переименовать в другой смысл;
- ярлык и цвет менять можно всегда.

Enum'ы **не удалять**: оставить как значения по умолчанию при первом заполнении справочника (сидер `CatalogSeeder`) и как типы в коде там, где от значения зависит логика (`PaymentMethod::Cash` → касса, `ExpenseCategory::Salary` → ведомость). Для логики в `meta` завести флаги (`is_cash`, `requires_receipt`, `is_salary`), чтобы код зависел от флага, а не от строки.

**Не переносить** (это состояния, а не справочники): `DealStatus`, `ExpenseStatus`, `DebtStatus`, `SalarySheetStatus`, `BonusStatus`, `ProductionStatus`, `PipelineType`, `Department`, `StageRequirement`, `DealEventType`, `UserRole`, `AccessLevel`, `Permission`.

## Шаг 14. Настройки → Тексты и подписи

Страница `/admin/settings/texts`, право `settings.company`.

- Названия пунктов меню и групп (сейчас зашиты в `$navigationLabel`) — вывести через `SiteSettings::menuLabel($key, $default)`.
- Подзаголовки страниц (`getSubheading()`), тексты пустых состояний.
- Клиентская страница `/track`: заголовок, приветствие, подпись, что показывать (позиции, сумма, срок), контакты и кнопка «Позвонить».
- Шаблоны уведомлений (замер, бонус, выплата, низкий остаток) с подстановками `{номер}`, `{клиент}`, `{сумма}`, `{дата}`.

## Шаг 15. Настройки → Регламенты и уведомления

- Время ежедневной проверки (сейчас `08:30` в `routes/console.php`) и её получатели по ролям.
- Пороги: за сколько дней предупреждать о сроке сдачи, через сколько часов сверх норматива этап считается застрявшим, минимальный остаток по умолчанию для новых материалов.
- Кому и что слать: матрица «событие × роль» (замер назначен, наряд создан, этап просрочен, сделка без движения N дней, бонус, выплата, низкий остаток). Хранить как json-настройку, читать в `DealObserver`, `DailyCheck`, `BonusAccrual`, `PayrollService`.
- Переключатель «Отгрузка блокируется при долге» (для шага 8) — включать автоматически или только вручную бухгалтером.

## Шаг 16. Журнал настроек и права — аудит

Таблица `settings_audit`: `key` (или `role`+`permission`), `old_value`, `new_value`, `user_id`, `created_at`. Пишется из `Setting::put()`, `AccessControl::set()`, справочников. Страница «Настройки → Журнал» (право `settings.access`): кто, когда и что поменял, с фильтром по разделу. Это единственный способ потом объяснить, почему «у всех вдруг пропал раздел».

---

## Матрица по умолчанию (значения `Permission::default()`)

`—` = Нет, `Ч` = Чтение, `С` = Только свои, `П` = Полный.

| Право | Менеджер | Директор | Бухгалтер | HR | Производство | Рабочий | Замерщик |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| work.sales_kanban | С | П | Ч | — | — | — | — |
| work.factory_kanban | Ч | П | Ч | Ч | П | Ч | — |
| work.overdue | С | П | Ч | Ч | П | — | — |
| work.deals | С | П | П | — | Ч | Ч | — |
| work.materials | Ч | П | П | — | П | — | — |
| work.stock_movements | Ч | П | П | — | П | — | — |
| finance.my_salary | П | П | П | П | П | П | П |
| finance.overview | — | П | П | — | — | — | — |
| finance.invoices | — | П | П | — | — | — | — |
| finance.incomes | — | П | П | — | — | — | — |
| finance.expenses | — | П | П | — | — | — | — |
| finance.cash | — | П | П | — | — | — | — |
| finance.debts | — | П | П | — | — | — | — |
| finance.payroll_shop | — | П | П | П | — | — | — |
| finance.salary_sheets | — | П | П | П | — | — | — |
| finance.bonuses | — | П | П | П | — | — | — |
| settings.stages | — | П | — | — | — | — | — |
| settings.price | — | П | — | — | — | — | — |
| settings.employees | — | П | Ч | П | — | — | — |
| settings.workshop | — | П | — | — | П | — | — |
| settings.finance | — | П | П | — | — | — | — |
| settings.company | — | П | Ч | — | — | — | — |
| settings.catalogs | — | П | — | — | — | — | — |
| settings.access | — | П | — | — | — | — | — |
| deals.delete | — | П | — | — | — | — | — |
| deals.cancel | С | П | — | — | — | — | — |
| deals.payment_flag | — | П | П | — | — | — | — |
| kanban.totals | — | П | П | — | — | — | — |
| kanban.money | С | П | П | — | — | — | — |
| employees.finance | — | П | П | П | — | — | — |
| factory.materials | — | П | — | — | П | П | — |

---

## Порядок работы для каждого шага

1. Миграция (если нужна) + модель + enum + фабрика.
2. Сервис и правила на сервере (наблюдатель/политика).
3. Экран Filament + blade в стиле проекта.
4. Перевод существующих мест на новый источник истины (без «дублирующей» логики).
5. Тесты: доступ по всем семи ролям, правило сервера, поведение UI.
6. `php artisan test && vendor/bin/pint && vendor/bin/phpstan analyse`.
7. `README.md` + `project.md`.
8. Коммит `feat(access): <шаг>` / `feat(settings): <шаг>` — только если владелец просит коммитить.

## Проверка всей части A (приёмка) — автоматизирована

`tests/Feature/RoleMatrixAcceptanceTest.php` проходит все 21 пункт меню каждой из семи ролей
и требует ровно 200 или 403. Ручной сценарий ниже оставлен для проверки глазами.

Завести по одному пользователю каждой роли и пройти сценарий:
- Менеджер: видит свою сделку и не видит чужую (список, канбан, просроченные, поиск, прямой URL → 403); не видит итог воронки; не может удалить сделку; «Моя зарплата» открывается, «Обзор финансов» — 403.
- Директор: открывает все 24 пункта меню.
- Бухгалтер: открывает все финансовые разделы, подтверждает расход, платит по долгу, ставит блокировку отгрузки; канбан продаж — без перетаскивания; «Этапы воронок» — 403.
- HR: «Зарплата», «Бонусы», «Сотрудники» — полные; «Касса», «Счета» — 403; оклад в карточке сотрудника виден.
- Производство: канбан завода полный, склад полный, «Воронка продаж» — 403, сумм на карточках нет.
- Смена уровня в «Роли и доступы» немедленно меняет доступ (проверить: выдать менеджеру `finance.overview` = Чтение → раздел появился, правка недоступна).

## Открытые вопросы владельцу (не блокируют шаги 1–5)

1. Бухгалтер и HR — это разные люди или один человек? Если один, роль «Финансы и кадры» проще матрицы из двух.
2. Блокировка отгрузки при долге (шаг 8) — ставится вручную бухгалтером или автоматически при любом остатке оплаты?
3. Менеджер видит чужие сделки «только для чтения» или не видит вовсе? В матрице — не видит.
4. Нужны ли роли на уровне отделов (несколько менеджеров под руководителем отдела с доступом к сделкам своей группы)? Если да — к `users` добавляется `team_id` и уровень `Own` расширяется до «свои и своей команды».
5. Справочник «Причины отказа» — кто им управляет: Директор или руководитель продаж?
