<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\ClientType;
use App\Enums\DealStatus;
use App\Enums\Permission;
use App\Filament\Actions\NewDealAction;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\AccessControl;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * База клиентов: кто заказывал, сколько раз и на какую сумму.
 *
 * Отдельной таблицы клиентов нет — клиент живёт в сделке, и это осознанно:
 * менеджер вводит заказ в одном месте. Здесь те же сделки, свёрнутые по
 * телефону: закрытая сделка не исчезает, а становится строкой истории
 * клиента. Отсюда же заводится повторный заказ — карточка заполнится сама.
 */
class Clients extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Работа';

    protected static ?string $navigationLabel = 'Клиенты';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'clients';

    protected string $view = 'filament.pages.clients';

    public static function canAccess(): bool
    {
        return AccessControl::can(Permission::WorkDeals);
    }

    public function getTitle(): string
    {
        return 'Клиенты';
    }

    protected function getHeaderActions(): array
    {
        return [NewDealAction::make()];
    }

    public function getSubheading(): ?string
    {
        $total = self::baseQuery()->whereNotNull('client_phone')->distinct('client_phone')->count('client_phone');

        return "{$total} клиентов по всем сделкам, включая закрытые. Повторный заказ — кнопкой «Новая сделка» в строке.";
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => $this->clients($search, $sortColumn, $sortDirection, $page, $recordsPerPage))
            ->searchable()
            ->searchPlaceholder('Имя, телефон, компания, БИН')
            ->defaultSort('last_deal_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->columns([
                TextColumn::make('title')
                    ->label('Клиент')
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (array $record): ?string => $record['subtitle'])
                    ->wrap(),

                TextColumn::make('client_phone')
                    ->label('Телефон')
                    ->copyable()
                    ->copyMessage('Скопировано')
                    ->description(fn (array $record): ?string => $record['client_email']),

                TextColumn::make('city')
                    ->label('Город')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('deals_count')
                    ->label('Сделок')
                    ->sortable()
                    ->alignEnd()
                    ->description(fn (array $record): ?string => $record['open_count'] > 0 ? "в работе {$record['open_count']}" : null),

                TextColumn::make('total_sum')
                    ->label('Заказал на')
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state))
                    ->sortable()
                    ->alignEnd()
                    ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney)),

                TextColumn::make('due_sum')
                    ->label('Должен')
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? Money::format((float) $state) : '—')
                    ->color(fn ($state): ?string => (float) $state > 0 ? 'danger' : null)
                    ->alignEnd()
                    ->visible(fn (): bool => AccessControl::can(Permission::KanbanMoney)),

                TextColumn::make('last_deal_at')
                    ->label('Последняя сделка')
                    ->date('d.m.Y')
                    ->sortable()
                    ->description(fn (array $record): string => $record['last_deal_number']),
            ])
            ->recordActions([
                Action::make('deals')
                    ->label('Сделки')
                    ->icon('heroicon-o-briefcase')
                    ->color('gray')
                    ->url(fn (array $record): string => DealResource::getUrl('index', [
                        'activeTab' => 'all',
                        'tableSearch' => $record['client_phone'],
                    ])),

                Action::make('newDeal')
                    ->label('Новая сделка')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->url(fn (array $record): string => DealResource::getUrl('create', ['client' => $record['client_phone']]))
                    ->visible(fn (): bool => auth()->user()?->can('create', Deal::class) ?? false),
            ])
            ->emptyStateHeading('Клиентов пока нет')
            ->emptyStateDescription('Клиенты появляются из сделок: заведите первую — и она будет здесь.');
    }

    /**
     * Сделки продаж, свёрнутые по телефону клиента, страницей.
     *
     * Агрегаты считает база (SUM/COUNT), а имя, компанию и город берём из
     * последней сделки клиента: если он сменил название или переехал,
     * в списке будет актуальное, а не первое попавшееся.
     */
    private function clients(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $perPage): LengthAwarePaginator
    {
        $closed = collect(DealStatus::cases())
            ->filter(fn (DealStatus $s): bool => $s->isClosed())
            ->map(fn (DealStatus $s): int => $s->value)
            ->implode(',');

        $query = self::baseQuery()
            ->whereNotNull('client_phone')
            ->where('client_phone', '!=', '')
            ->when(filled($search), function (Builder $q) use ($search): void {
                $like = '%'.trim((string) $search).'%';
                $q->where(function (Builder $inner) use ($like): void {
                    $inner->where('client_name', 'like', $like)
                        ->orWhere('client_phone', 'like', $like)
                        ->orWhere('client_company', 'like', $like)
                        ->orWhere('client_bin', 'like', $like);
                });
            })
            ->groupBy('client_phone')
            ->selectRaw('client_phone')
            ->selectRaw('COUNT(*) as deals_count')
            ->selectRaw("SUM(CASE WHEN status_id IN ({$closed}) THEN 0 ELSE 1 END) as open_count")
            ->selectRaw('COALESCE(SUM(total_price), 0) as total_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN total_price > prepayment THEN total_price - prepayment ELSE 0 END), 0) as due_sum')
            ->selectRaw('MAX(created_at) as last_deal_at')
            ->selectRaw('MIN(created_at) as first_deal_at');

        $sortable = ['deals_count', 'total_sum', 'last_deal_at'];
        $column = in_array($sortColumn, $sortable, true) ? $sortColumn : 'last_deal_at';
        $query->orderBy($column, $sortDirection === 'asc' ? 'asc' : 'desc')->orderBy('client_phone');

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $phones = collect($paginator->items())->pluck('client_phone')->all();

        /** @var Collection<string, Deal> $latest */
        $latest = self::baseQuery()
            ->whereIn('client_phone', $phones)
            // id — второй ключ: две сделки, заведённые в одну секунду, иначе сортируются случайно.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->unique('client_phone')
            ->keyBy('client_phone');

        // Строки агрегатного запроса — обычные массивы: у них нет id, и как модели
        // Deal они бы только путали (Larastan прав: таких свойств у Deal нет).
        return $paginator->through(function (Deal $row) use ($latest): array {
            /** @var array<string, mixed> $agg */
            $agg = $row->getAttributes();
            $phone = (string) $agg['client_phone'];
            $deal = $latest->get($phone);
            $isCompany = $deal !== null && $deal->client_type === ClientType::Company && filled($deal->client_company);

            return [
                'key' => $phone,
                'client_phone' => $phone,
                'title' => $isCompany ? (string) $deal->client_company : (string) ($deal->client_name ?? $phone),
                'subtitle' => $isCompany
                    ? trim(($deal->client_name ?? '').(filled($deal->client_bin) ? ' · БИН '.$deal->client_bin : ''), ' ·')
                    : null,
                'client_email' => $deal?->client_email,
                'city' => $deal?->city,
                'deals_count' => (int) $agg['deals_count'],
                'open_count' => (int) $agg['open_count'],
                'total_sum' => (float) $agg['total_sum'],
                'due_sum' => (float) $agg['due_sum'],
                'last_deal_at' => $agg['last_deal_at'],
                'last_deal_number' => $deal->number ?? '',
            ];
        });
    }

    /** @return Builder<Deal> */
    private static function baseQuery(): Builder
    {
        return Deal::query()->visibleTo(auth()->user())->sales();
    }
}
