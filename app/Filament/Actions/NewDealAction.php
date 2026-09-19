<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\Action;

/**
 * «Новая сделка» — одна и та же кнопка на инфопанели, воронке, у клиентов и в
 * списке сделок. Раньше она была только в списке, и её искали.
 */
final class NewDealAction
{
    public static function make(): Action
    {
        return Action::make('newDeal')
            ->label('Новая сделка')
            ->icon('heroicon-m-plus')
            ->color('primary')
            ->url(fn (): string => DealResource::getUrl('create'))
            ->visible(fn (): bool => self::allowed());
    }

    public static function allowed(): bool
    {
        return auth()->user()?->can('create', Deal::class) ?? false;
    }
}
