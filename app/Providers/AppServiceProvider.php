<?php

namespace App\Providers;

use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Pages\BasePage;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
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
        // Администратор — глобальный суперпользователь: иначе каждую новую
        // политику пришлось бы отдельно учить пропускать админа.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        $this->configureFilamentDefaults();
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
