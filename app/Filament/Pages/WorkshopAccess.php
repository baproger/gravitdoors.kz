<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Код входа на планшет цеха: показать, скопировать, перевыпустить. */
class WorkshopAccess extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Экран цеха';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'workshop-access';

    protected string $view = 'filament.pages.workshop-access';

    public string $code = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->code = Setting::workshopCode();
    }

    public function getTitle(): string
    {
        return 'Экран цеха';
    }

    public function getSubheading(): ?string
    {
        return 'Планшет в цехе открывается по этому коду — без логина и пароля.';
    }

    public function screenUrl(): string
    {
        return route('workshop.screen');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rotate')
                ->label('Новый код')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Выпустить новый код?')
                ->modalDescription('Старый код перестанет работать — планшеты в цехе придётся авторизовать заново.')
                ->action(function (): void {
                    $this->code = Setting::rotateWorkshopCode();

                    Notification::make()->success()->title('Код обновлён')->body("Новый код: {$this->code}")->send();
                }),
        ];
    }
}
