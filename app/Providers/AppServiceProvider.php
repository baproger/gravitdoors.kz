<?php

namespace App\Providers;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\User;
use App\Services\AccessControl;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Pages\BasePage;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Директор — глобальный суперпользователь: иначе каждую новую политику
        // пришлось бы отдельно учить пропускать хозяина системы. Остальные роли
        // проходят через реестр прав (AccessControl), а не через эту дверь.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        $this->registerAccessDirective();
        $this->configureFilamentDefaults();
    }

    /**
     * `@access('work.deals', 'full')` в blade — короткая форма проверки права.
     *
     * Директива, а не сравнение роли в шаблоне: разметка не должна знать,
     * какие роли существуют, иначе матрица в настройках перестанет работать.
     */
    private function registerAccessDirective(): void
    {
        Blade::if('access', function (string $permission, string $level = 'read'): bool {
            $right = Permission::tryFrom($permission);
            $min = AccessLevel::tryFrom($level) ?? AccessLevel::Read;

            return $right !== null && AccessControl::can($right, $min);
        });
    }

    /**
     * Единые правила оформления для всей панели.
     *
     * Задаются здесь, а не в каждом ресурсе: иначе одинаковые по смыслу окна
     * получаются разной ширины и плотности, и панель выглядит собранной из
     * кусков. Модалки формы — широкие: в узком окне двухколоночная форма
     * сжимала поля дат до нечитаемых «05.11.198».
     */
    private function configureFilamentDefaults(): void
    {
        // Календарь Filament вместо браузерного: в нём месяц выбирается списком,
        // а год вводится отдельным полем. У родного поля даты в Chrome год
        // перебирается стрелками по одному — для дат рождения это мучение.
        DatePicker::configureUsing(fn (DatePicker $picker) => $picker
            ->native(false)
            ->displayFormat('d.m.Y')
            ->closeOnDateSelection());

        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->displayFormat('d.m.Y H:i'));

        CreateAction::configureUsing(fn (CreateAction $action) => $action
            ->modalWidth(Width::FiveExtraLarge)
            ->slideOver(false));

        EditAction::configureUsing(fn (EditAction $action) => $action
            ->modalWidth(Width::FiveExtraLarge)
            ->slideOver(false));

        DeleteAction::configureUsing(fn (DeleteAction $action) => $action
            ->modalWidth(Width::Medium));

        // Компактные секции: шапка тоньше, воздуха меньше — форма помещается
        // на экран без прокрутки.
        Section::configureUsing(fn (Section $section) => $section->compact());

        // «Сохранить / Отмена» прилипают к низу экрана. У карточки сделки правая
        // колонка выше формы, и кнопки оказывались далеко под вкладками —
        // приходилось листать вниз, чтобы сохранить правку в первом же поле.
        BasePage::stickyFormActions();

        // Без deferLoading: отложенная загрузка экономит доли секунды, но строки
        // приезжают вторым запросом — таблица моргает, а страница отдаётся пустой.
        Table::configureUsing(fn (Table $table) => $table
            ->striped()
            ->paginationPageOptions([25, 50, 100]));
    }
}
