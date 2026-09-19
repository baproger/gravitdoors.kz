<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DealEventType;
use App\Filament\Actions\NewDealAction;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Services\DashboardStats;
use App\Support\Period;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Инфопанель: всё, что нужно увидеть с порога, на одном экране.
 *
 * Свой экран, а не набор виджетов Filament: показателей много, они связаны
 * общим периодом, и раскладка плитками читается быстрее, чем десяток
 * независимых карточек. Что именно видно — решает реестр прав, поэтому у
 * менеджера это его воронка, у цеха — загрузка, у директора — всё сразу.
 */
class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Инфопанель';

    protected static ?int $navigationSort = -2;

    protected static ?string $slug = '/';

    protected string $view = 'filament.pages.dashboard';

    /** Период живёт в адресной строке: ссылкой на отчёт можно поделиться. */
    #[Url(except: Period::MONTH)]
    public string $period = Period::MONTH;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected function getHeaderActions(): array
    {
        return [NewDealAction::make()];
    }

    public function getTitle(): string
    {
        return 'Инфопанель';
    }

    public function getSubheading(): ?string
    {
        return 'Показатели '.$this->periodValue()->hint().'. Период меняется переключателем справа.';
    }

    public function periodValue(): Period
    {
        return Period::make($this->period, $this->from ?: null, $this->to ?: null);
    }

    public function stats(): DashboardStats
    {
        return new DashboardStats($this->periodValue(), auth()->user());
    }

    /** @return array<string, string> */
    public function periodOptions(): array
    {
        return Period::options();
    }

    /**
     * «Замерял» — единственное, что замерщик делает в системе руками.
     *
     * Модалка живёт на инфопанели, а не в карточке сделки: карточка замерщику
     * не открывается (ни списка, ни воронки у него нет), и кнопка вела бы на
     * 403. Запись берётся из аргументов, как у «Готово ✓» на канбане.
     */
    public function measureAction(): Action
    {
        return Action::make('measure')
            ->label('Замерял')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('success')
            ->modalWidth('lg')
            ->modalHeading(function (array $arguments): string {
                $deal = $this->dealFrom($arguments);

                return $deal ? "Замер по {$deal->number}" : 'Замер';
            })
            ->modalDescription(function (array $arguments): string {
                $deal = $this->dealFrom($arguments);

                if (! $deal) {
                    return '';
                }

                return collect([
                    $deal->clientTitle(),
                    $deal->client_address,
                    $deal->client_phone,
                    $deal->measured_at?->format('d.m.Y H:i'),
                ])->filter()->implode(' · ');
            })
            ->modalSubmitActionLabel('Записать замер')
            ->modalCancelActionLabel('Отмена')
            ->fillForm(function (array $arguments): array {
                $deal = $this->dealFrom($arguments);

                return [
                    'measurement_height' => $deal?->measurement_height,
                    'measurement_width' => $deal?->measurement_width,
                    'measurement_comment' => $deal?->measurement_comment,
                ];
            })
            ->schema([
                TextInput::make('measurement_height')
                    ->label('Высота, мм')
                    ->numeric()
                    ->required()
                    ->minValue(config('gravit.pricing.min_height'))
                    ->maxValue(config('gravit.pricing.max_height'))
                    ->helperText(config('gravit.pricing.min_height').'–'.config('gravit.pricing.max_height').' мм'),

                TextInput::make('measurement_width')
                    ->label('Ширина, мм')
                    ->numeric()
                    ->required()
                    ->minValue(config('gravit.pricing.min_width'))
                    ->maxValue(config('gravit.pricing.max_width'))
                    ->helperText(config('gravit.pricing.min_width').'–'.config('gravit.pricing.max_width').' мм'),

                Textarea::make('measurement_comment')
                    ->label('Комментарий')
                    ->placeholder('Проём кривой, нужен доборный профиль')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ])
            ->action(fn (array $arguments, array $data) => $this->measure($arguments, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    public function measure(array $arguments, array $data): void
    {
        $deal = $this->dealFrom($arguments);

        // Скрытая кнопка — не защита: право проверяется здесь, а не в разметке.
        abort_unless($deal !== null && (auth()->user()?->can('measure', $deal) ?? false), 403);

        $deal->forceFill([
            'measurement_height' => (int) $data['measurement_height'],
            'measurement_width' => (int) $data['measurement_width'],
            'measurement_comment' => trim((string) ($data['measurement_comment'] ?? '')) ?: null,
            'measurement_done_at' => now(),
            'measurement_by_id' => auth()->id(),
            // Тихо: иначе DealObserver положит рядом вторую запись «Изменено:
            // Высота замера, Ширина замера…» о том же самом. Событие «Замер»
            // ниже говорит то же короче.
        ])->saveQuietly();

        DealEvent::record(
            $deal,
            DealEventType::Survey,
            'Замер проведён: '.$deal->measurementSize()
                .($deal->measurement_comment ? '. '.$deal->measurement_comment : ''),
            auth()->user(),
        );

        $this->notifyManager($deal);

        Notification::make()
            ->success()
            ->title('Замер записан')
            ->body($deal->number.' · '.$deal->measurementSize())
            ->send();
    }

    /** Менеджер ждёт цифры, чтобы двигать сделку дальше: этап замерщик не трогает. */
    private function notifyManager(Deal $deal): void
    {
        $manager = $deal->manager;

        if (! $manager || ! $manager->is_active || $manager->is(auth()->user())) {
            return;
        }

        Notification::make()
            ->title($deal->number.' · замер проведён')
            ->body(trim($deal->measurementSize().($deal->measurement_comment ? '. '.$deal->measurement_comment : '')))
            ->icon('heroicon-o-clipboard-document-check')
            ->success()
            ->actions([DealResource::openAction($deal)])
            ->sendToDatabase($manager);
    }

    /** @param  array<string, mixed>  $arguments */
    private function dealFrom(array $arguments): ?Deal
    {
        return Deal::query()->sales()->find((int) ($arguments['deal'] ?? 0));
    }

    public function setPeriod(string $key): void
    {
        $this->period = array_key_exists($key, Period::options()) ? $key : Period::MONTH;

        if ($this->period !== Period::CUSTOM) {
            $this->from = '';
            $this->to = '';
        }
    }
}
