<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bonuses\Schemas;

use App\Models\Deal;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BonusForm
{
    public static function configure(Schema $schema): Schema
    {
        $months = [];

        foreach (range(0, 5) as $back) {
            $date = now()->subMonths($back);
            $months[$date->format('Y-m')] = mb_convert_case($date->translatedFormat('F Y'), MB_CASE_TITLE);
        }

        return $schema->columns(1)->components([
            Section::make()
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Сотрудник')
                        ->options(fn (): array => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->native(false),
                    Select::make('month')->label('За месяц')->options($months)->default(now()->format('Y-m'))->required()->native(false),
                    TextInput::make('amount')->label('Сумма')->numeric()->minValue(1)->required()->suffix(config('gravit.currency.symbol')),
                    Select::make('deal_id')
                        ->label('За сделку')
                        ->options(fn (): array => Deal::query()->sales()->orderByDesc('id')->limit(200)->get()
                            ->mapWithKeys(fn (Deal $d): array => [$d->id => "{$d->number} · {$d->title}"])->all())
                        ->searchable()
                        ->native(false),
                    TextInput::make('reason')->label('За что')->required()->maxLength(255)->columnSpanFull(),
                ]),
        ]);
    }
}
