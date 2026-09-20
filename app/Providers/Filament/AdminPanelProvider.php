<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Support\Avatars\InitialsAvatar;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Своя страница входа: витрина слева, форма справа. Логика входа
            // остаётся филаментовской — лимит попыток и второй фактор.
            ->login(Login::class)
            // Второй фактор — приложение-аутентификатор (Google Authenticator, Яндекс.Ключ):
            // пароль директора или бухгалтера могут подсмотреть или выманить, а телефон —
            // нет. Включается каждым в своём профиле; коды восстановления — на случай
            // потери телефона. Директору стоит включить в первый же день.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->brandName('Gravit ERP'),
            ])
            // Каждый правит свой профиль сам: имя, контакты, аватар и пароль.
            // Роль и оклад остаются в разделе «Сотрудники» у администратора.
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('Gravit ERP')
            // Знак в сайдбаре — своя разметка, а не картинка: круглая иконка и
            // название рядом. Широкий логотип с надписью занимал всю ширину
            // сайдбара и всё равно не читался в 32 пикселя. Название набрано
            // шрифтом панели — остаётся чётким и подхватывает цвет темы,
            // поэтому отдельная версия для тёмной темы не нужна.
            ->brandLogo(fn (): View => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            // Значок вкладки — одна дверь из логотипа: надпись в 16 пикселей
            // всё равно не читается.
            ->favicon(asset('images/gravit-favicon.png'))
            ->colors([
                // Готовая синяя палитра, а не Color::hex('#2F6FED'): из светлого hex
                // Filament строил палитру, у которой тёмный тон не проходил проверку
                // контраста с белым текстом, и кнопки рисовались бледными с тёмной
                // подписью — «Сохранить» выглядела отключённой.
                'primary' => Color::Blue,
                'gray' => Color::Slate,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                // Кадры: отдельный цвет, чтобы роль HR и её события не путались с менеджерскими.
                'violet' => Color::Violet,
            ])
            // Кружки с инициалами рисуются у себя. По умолчанию Filament просит
            // их у ui-avatars.com, отправляя туда имя сотрудника, — чужой сервер
            // и фамилии кадрового состава в чужих логах на каждой странице.
            ->defaultAvatarProvider(InitialsAvatar::class)
            // Шрифт намеренно не задаётся: без ->font() Filament берёт Inter,
            // который возит с собой и публикует в public/fonts при установке.
            // Строка ->font('Inter') заставляла каждую страницу ждать ответа
            // от fonts.bunny.net — лишние DNS, TLS и два запроса до первой
            // буквы, да ещё и чужой сервер в списке того, что может упасть.
            // ТЗ: Light SaaS. Переключатель темы у пользователя остаётся.
            ->defaultThemeMode(ThemeMode::Light)
            // Канбан на шесть колонок задыхается в фиксированной ширине контента.
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Работа')->icon('heroicon-o-squares-2x2'),
                NavigationGroup::make('Финансы')->icon('heroicon-o-banknotes'),
                NavigationGroup::make('Настройки')->icon('heroicon-o-cog-8-tooth')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Виджетов нет: показатели собраны на своей инфопанели (App\Filament\Pages\Dashboard),
            // где они связаны общим периодом и правами доступа.
            ->widgets([])
            // «Новая сделка» в шапке на каждой странице: главное действие менеджера
            // не должно зависеть от того, какой раздел открыт.
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn (): View => view('filament.topbar-new-deal'))
            ->databaseNotifications()
            // По умолчанию Filament опрашивает уведомления каждые 30 секунд из
            // каждой открытой вкладки: десять вкладок — двадцать запросов в минуту
            // на пустом месте, а PHP-процессов на сервере три. Раз в две минуты
            // достаточно: уведомления здесь дневные (склад, просрочка), не чат.
            ->databaseNotificationsPolling('120s')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
