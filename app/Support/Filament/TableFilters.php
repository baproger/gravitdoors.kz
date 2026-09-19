<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Фильтры, которые нужны почти в каждой таблице.
 *
 * Собраны в одном месте, чтобы «за период» и «за месяц» везде выглядели и
 * работали одинаково: иначе на каждой странице заводится свой вариант с
 * другими подписями и другой логикой границ.
 */
final class TableFilters
{
    /** Произвольный период «с — по» по дате. Пустая граница не ограничивает. */
    public static function period(string $column, string $label = 'Период'): Filter
    {
        return Filter::make($column.'_period')
            ->label($label)
            ->columnSpan(2)
            ->schema([
                DatePicker::make('from')->label('С')->placeholder('любая дата'),
                DatePicker::make('until')->label('По')->placeholder('любая дата'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate($column, '<=', $date)))
            ->indicateUsing(function (array $data) use ($label): ?string {
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;

                return match (true) {
                    $from && $until => $label.': '.self::date($from).' — '.self::date($until),
                    (bool) $from => $label.' с '.self::date($from),
                    (bool) $until => $label.' по '.self::date($until),
                    default => null,
                };
            });
    }

    /** Быстрый выбор месяца за последний год — там, где период почти всегда «месяц». */
    public static function month(string $column, string $label = 'Месяц'): Filter
    {
        $options = [];

        foreach (range(0, 11) as $back) {
            $date = now()->subMonths($back);
            $options[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return Filter::make($column.'_month')
            ->label($label)
            ->schema([
                Select::make('month')->label($label)->options($options)->native(false)->placeholder('Все месяцы'),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $month = $data['month'] ?? null;

                if (! $month) {
                    return $query;
                }

                $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

                return $query->whereBetween($column, [$start, $start->endOfMonth()]);
            })
            ->indicateUsing(fn (array $data): ?string => ($data['month'] ?? null)
                ? $label.': '.($data['month'])
                : null);
    }

    private static function date(string $value): string
    {
        return CarbonImmutable::parse($value)->format('d.m.Y');
    }
}
