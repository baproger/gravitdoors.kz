<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Финансовые настройки: ставка бонуса менеджеру и режим его утверждения. Только администратор. */
class FinanceSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationLabel = 'Финансы';

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'finance-settings';

    protected string $view = 'filament.pages.finance-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return 'Настройки финансов';
    }

    public function getSubheading(): ?string
    {
        return 'Бонус менеджеру считается от суммы сделки в момент её закрытия. У сотрудника может быть своя ставка — в его карточке.';
    }

    public function percent(): float
    {
        return Setting::managerBonusPercent();
    }

    public function autoApprove(): bool
    {
        return Setting::managerBonusAutoApprove();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Изменить')
                ->icon('heroicon-o-pencil-square')
                ->modalHeading('Бонус менеджеру')
                ->schema([
                    TextInput::make('percent')
                        ->label('Процент от суммы сделки')
                        ->helperText('0 — автобонус выключен. Например, 2 → с сделки на 1 000 000 ₸ менеджер получит 20 000 ₸.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.1)
                        ->default(fn (): float => $this->percent())
                        ->required()
                        ->suffix('%'),
                    Toggle::make('auto_approve')
                        ->label('Утверждать автоматически')
                        ->helperText('Выключено — бонус ждёт утверждения в разделе «Бонусы».')
                        ->default(fn (): bool => $this->autoApprove()),
                ])
                ->action(function (array $data): void {
                    Setting::put(Setting::MANAGER_BONUS_PERCENT, (string) (float) $data['percent']);
                    Setting::put(Setting::MANAGER_BONUS_AUTO_APPROVE, ! empty($data['auto_approve']) ? '1' : '0');

                    Notification::make()->success()->title('Настройки сохранены')->send();
                }),
        ];
    }
}
