<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AccessControl;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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

    /** @return list<UserRole> */
    public function roles(): array
    {
        return UserRole::cases();
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

    public function levelOf(UserRole $role, Permission $permission): AccessLevel
    {
        return AccessControl::level($role, $permission);
    }

    public function isOverridden(UserRole $role, Permission $permission): bool
    {
        return AccessControl::isOverridden($role, $permission);
    }

    /** Сколько отличий от рекомендованных значений у роли — для пометки в шапке колонки. */
    public function overrideCount(UserRole $role): int
    {
        $count = 0;

        foreach (Permission::cases() as $permission) {
            if (AccessControl::isOverridden($role, $permission)) {
                $count++;
            }
        }

        return $count;
    }

    /** Клик по ячейке матрицы. */
    public function setLevel(string $role, string $permission, string $level): void
    {
        $this->guard();

        $roleCase = UserRole::tryFrom($role);
        $permissionCase = Permission::tryFrom($permission);
        $levelCase = AccessLevel::tryFrom($level);

        if (! $roleCase || ! $permissionCase || ! $levelCase) {
            Notification::make()->danger()->title('Неизвестное право или уровень')->send();

            return;
        }

        try {
            AccessControl::set($roleCase, $permissionCase, $levelCase, auth()->user());

            Notification::make()
                ->success()
                ->title($roleCase->getLabel())
                ->body("«{$permissionCase->getLabel()}» → {$levelCase->getLabel()}")
                ->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Не сохранено')->body(collect($e->errors())->flatten()->implode(' '))->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetRole')
                ->label('Сбросить роль')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->modalHeading('Вернуть роль к рекомендованным правам?')
                ->modalDescription('Все отличия этой роли от матрицы из ТЗ будут удалены.')
                ->schema([
                    Select::make('role')
                        ->label('Роль')
                        ->options(collect(UserRole::cases())
                            ->reject(fn (UserRole $role): bool => $role === UserRole::Admin)
                            ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->getLabel()])
                            ->all())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $this->guard();

                    $role = UserRole::tryFrom((string) $data['role']);

                    if (! $role) {
                        return;
                    }

                    AccessControl::reset($role);

                    Notification::make()->success()->title("{$role->getLabel()}: права сброшены к рекомендованным")->send();
                }),
        ];
    }

    /** Права проверяются и на действии: скрытая кнопка — не защита. */
    private function guard(): void
    {
        /** @var User|null $user */
        $user = auth()->user();

        abort_unless(AccessControl::allows($user, Permission::SettingsAccess, AccessLevel::Full), 403);
    }
}
