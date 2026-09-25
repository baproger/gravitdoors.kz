<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Роли и доступы: кто какие разделы видит и что в них может.
 *
 * Матрица — единственное место, где меняется доступ. Колонка директора
 * только для чтения: иначе настройками можно запереть хозяина системы.
 */
class AccessMatrix extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Роли и доступы';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'access';

    protected string $view = 'filament.pages.access-matrix';

    /** Палитра ролей — та же, что у этапов воронок. */
    private const COLORS = ['gray', 'info', 'primary', 'success', 'warning', 'danger', 'violet', 'teal'];

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::SettingsAccess, AccessLevel::Full);
    }

    public function getTitle(): string
    {
        return 'Роли и доступы';
    }

    public function getSubheading(): ?string
    {
        return 'Уровень доступа роли к каждому разделу. Изменение действует сразу, без перезапуска.';
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return Role::cached()->values()->all();
    }

    /** @return array<string, list<Permission>> группа → права */
    public function groups(): array
    {
        $groups = [];

        foreach (Permission::groups() as $group) {
            $groups[$group] = Permission::ofGroup($group);
        }

        return $groups;
    }

    public function levelOf(Role $role, Permission $permission): AccessLevel
    {
        return AccessControl::level($role->code, $permission);
    }

    public function isOverridden(Role $role, Permission $permission): bool
    {
        return AccessControl::isOverridden($role->code, $permission);
    }

    /** Сколько отличий от рекомендованных значений у роли — для пометки в шапке колонки. */
    public function overrideCount(Role $role): int
    {
        $count = 0;

        foreach (Permission::cases() as $permission) {
            if (AccessControl::isOverridden($role->code, $permission)) {
                $count++;
            }
        }

        return $count;
    }

    /** Клик по ячейке матрицы. */
    public function setLevel(string $role, string $permission, string $level): void
    {
        $this->guard();

        $roleModel = Role::byCode($role);
        $permissionCase = Permission::tryFrom($permission);
        $levelCase = AccessLevel::tryFrom($level);

        if (! $roleModel || ! $permissionCase || ! $levelCase) {
            Notification::make()->danger()->title('Неизвестная роль, право или уровень')->send();

            return;
        }

        try {
            AccessControl::set($roleModel->code, $permissionCase, $levelCase, auth()->user());

            Notification::make()
                ->success()
                ->title($roleModel->getLabel())
                ->body("«{$permissionCase->getLabel()}» → {$levelCase->getLabel()}")
                ->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Не сохранено')->body(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [$this->createRoleAction(), $this->editRoleAction(), $this->resetRoleAction(), $this->deleteRoleAction()];
    }

    /**
     * Новая роль заводится копией существующей.
     *
     * С нуля пришлось бы прокликать 32 права, и роль почти наверняка осталась
     * бы полупустой. «Как менеджер, только без финансов» — один выбор и потом
     * пара правок в матрице.
     */
    public function createRoleAction(): Action
    {
        return Action::make('createRole')
            ->label('Создать роль')
            ->icon('heroicon-o-plus')
            ->modalHeading('Новая роль')
            ->modalDescription('Права скопируются с выбранной роли — потом поправьте их в матрице.')
            ->modalSubmitActionLabel('Создать')
            ->modalWidth('lg')
            ->schema([
                TextInput::make('name')
                    ->label('Название')
                    ->placeholder('Кладовщик')
                    ->required()
                    ->maxLength(Role::NAME_MAX),

                Select::make('copy_from')
                    ->label('Взять права у роли')
                    ->options(fn (): array => $this->roleOptions())
                    ->helperText('Все 32 права скопируются с неё, кроме «Роли и доступы» — это остаётся у директора')
                    ->required()
                    ->native(false),

                Textarea::make('hint')
                    ->label('Описание')
                    ->placeholder('Принимает материалы и ведёт склад')
                    ->rows(2)
                    ->maxLength(255),

                Select::make('color')
                    ->label('Цвет')
                    ->options(array_combine(self::COLORS, self::COLORS))
                    ->default('gray')
                    ->native(false),

                Toggle::make('does_surveys')
                    ->label('Ездит на замеры')
                    ->helperText('Получает уведомления о выездах и кнопку «Замерял» на инфопанели'),

                Toggle::make('is_factory_staff')
                    ->label('Работает в цеху')
                    ->helperText('Идёт сдельная оплата за закрытый этап, виден в выборе исполнителя на планшете'),
            ])
            ->action(function (array $data): void {
                $this->guard();

                $source = Role::byCode((string) $data['copy_from']);

                if (! $source) {
                    Notification::make()->danger()->title('Роль-образец не найдена')->send();

                    return;
                }

                $role = Role::create([
                    'code' => $this->freeCode((string) $data['name']),
                    'name' => trim((string) $data['name']),
                    'hint' => $data['hint'] ?: null,
                    'color' => $data['color'] ?? 'gray',
                    'does_surveys' => (bool) ($data['does_surveys'] ?? false),
                    'is_factory_staff' => (bool) ($data['is_factory_staff'] ?? false),
                    'is_system' => false,
                    'sort' => (int) Role::query()->max('sort') + 10,
                ]);

                AccessControl::copy($source->code, $role->code, auth()->user());

                Notification::make()->success()
                    ->title("Роль «{$role->name}» создана")
                    ->body("Права скопированы с «{$source->name}». Поправьте их в матрице.")
                    ->send();
            });
    }

    /** Название, цвет и поведение — у любой роли; код роли заморожен навсегда. */
    public function editRoleAction(): Action
    {
        return Action::make('editRole')
            ->label('Настроить роль')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalHeading('Настройки роли')
            ->modalWidth('lg')
            ->schema([
                Select::make('role')
                    ->label('Роль')
                    ->options(fn (): array => $this->roleOptions(withAdmin: true))
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $role = Role::byCode((string) $state);

                        if (! $role) {
                            return;
                        }

                        $set('name', $role->name);
                        $set('hint', $role->hint);
                        $set('color', $role->color);
                        $set('does_surveys', $role->does_surveys);
                        $set('is_factory_staff', $role->is_factory_staff);
                        $set('is_active', $role->is_active);
                    }),

                TextInput::make('name')->label('Название')->required()->maxLength(Role::NAME_MAX),
                Textarea::make('hint')->label('Описание')->rows(2)->maxLength(255),
                Select::make('color')->label('Цвет')->options(array_combine(self::COLORS, self::COLORS))->native(false),

                Toggle::make('does_surveys')->label('Ездит на замеры'),
                Toggle::make('is_factory_staff')->label('Работает в цеху'),

                Toggle::make('is_active')
                    ->label('Предлагать в карточке сотрудника')
                    ->helperText('Выключенная роль остаётся у тех, кому уже назначена, но новым сотрудникам не предлагается')
                    ->visible(fn (Get $get): bool => Role::byCode((string) $get('role'))?->is_system === false),
            ])
            ->action(function (array $data): void {
                $this->guard();

                $role = Role::byCode((string) $data['role']);

                if (! $role) {
                    return;
                }

                $role->update([
                    'name' => trim((string) $data['name']),
                    'hint' => $data['hint'] ?: null,
                    'color' => $data['color'] ?? $role->color,
                    'does_surveys' => (bool) ($data['does_surveys'] ?? false),
                    'is_factory_staff' => (bool) ($data['is_factory_staff'] ?? false),
                    // Базовую роль скрыть нельзя: на ней держатся автоматизации.
                    'is_active' => $role->is_system ? true : (bool) ($data['is_active'] ?? true),
                ]);

                AccessControl::flush();

                Notification::make()->success()->title("Роль «{$role->name}» сохранена")->send();
            });
    }

    public function resetRoleAction(): Action
    {
        return Action::make('resetRole')
            ->label('Сбросить права')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->modalHeading('Вернуть роль к рекомендованным правам?')
            ->modalDescription('Все отличия этой роли от матрицы из ТЗ будут удалены. Придуманная роль останется без прав — раздайте их заново или скопируйте с другой роли.')
            ->schema([
                Select::make('role')
                    ->label('Роль')
                    ->options(fn (): array => $this->roleOptions())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $this->guard();

                $role = Role::byCode((string) $data['role']);

                if (! $role) {
                    return;
                }

                AccessControl::reset($role->code);

                Notification::make()->success()->title("{$role->getLabel()}: права сброшены к рекомендованным")->send();
            });
    }

    /**
     * Удалить можно только придуманную роль без сотрудников.
     *
     * Базовые семь остаются: на их коде держатся автоматизации (замеры,
     * сдельная оплата цеха, отдел в истории сделки), а сотрудник с удалённой
     * ролью остался бы вообще без прав и без объяснения почему.
     */
    public function deleteRoleAction(): Action
    {
        return Action::make('deleteRole')
            ->label('Удалить роль')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => Role::cached()->contains(fn (Role $role): bool => ! $role->is_system))
            ->modalHeading('Удалить роль?')
            ->modalDescription('Вместе с ролью удалятся её отличия от рекомендованных прав. Базовые роли и роли с сотрудниками не удаляются.')
            ->modalSubmitActionLabel('Удалить')
            ->schema([
                Select::make('role')
                    ->label('Роль')
                    ->options(fn (): array => Role::cached()
                        ->filter(fn (Role $role): bool => $role->canBeDeleted())
                        ->mapWithKeys(fn (Role $role): array => [$role->code => $role->name])
                        ->all())
                    ->helperText('В списке только придуманные роли, на которых никто не работает')
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data): void {
                $this->guard();

                $role = Role::byCode((string) $data['role']);

                if (! $role?->canBeDeleted()) {
                    Notification::make()->danger()
                        ->title('Роль не удалена')
                        ->body('Базовую роль удалить нельзя, а на этой ещё работают сотрудники.')
                        ->send();

                    return;
                }

                AccessControl::reset($role->code);
                $name = $role->name;
                $role->delete();

                AccessControl::flush();

                Notification::make()->success()->title("Роль «{$name}» удалена")->send();
            });
    }

    /** @return array<string, string> */
    private function roleOptions(bool $withAdmin = false): array
    {
        return Role::cached()
            ->filter(fn (Role $role): bool => $withAdmin || ! $role->isAdmin())
            ->mapWithKeys(fn (Role $role): array => [$role->code => $role->name])
            ->all();
    }

    /**
     * Код роли — латиницей из названия, он уходит в `users.role` навсегда.
     * Русское название кода не даст, поэтому для него есть запасной вариант.
     */
    private function freeCode(string $name): string
    {
        $base = Str::of($name)->slug('_')->limit(Role::CODE_MAX - 3, '')->toString();

        if ($base === '') {
            $base = 'role';
        }

        $code = $base;
        $suffix = 1;

        while (Role::query()->where('code', $code)->exists()) {
            $code = $base.'_'.++$suffix;
        }

        return $code;
    }

    /** Права проверяются и на действии: скрытая кнопка — не защита. */
    private function guard(): void
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless(AccessControl::allows($user, Permission::SettingsAccess, AccessLevel::Full), 403);
    }
}
