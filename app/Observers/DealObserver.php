<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\UserRole;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class DealObserver
{
    public function updated(Deal $deal): void
    {
        // Дата замера назначена или перенесена — замерщик должен узнать об этом
        // сам, а не из устного «съезди завтра на Абая».
        if ($deal->wasChanged('measured_at') && $deal->measured_at !== null) {
            $this->notifySurveyors($deal);
        }
    }

    public function created(Deal $deal): void
    {
        if ($deal->measured_at !== null) {
            $this->notifySurveyors($deal);
        }
    }

    private function notifySurveyors(Deal $deal): void
    {
        $surveyors = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Surveyor->value, UserRole::Master->value])
            ->get();

        if ($surveyors->isEmpty()) {
            return;
        }

        $where = collect([$deal->city, $deal->client_address])->filter()->implode(', ');

        Notification::make()
            ->title('Замер '.$deal->measured_at->format('d.m.Y'))
            ->body(trim("{$deal->number} · {$deal->clientTitle()}".($where !== '' ? " · {$where}" : '')
                .($deal->client_phone ? " · {$deal->client_phone}" : '')))
            ->icon('heroicon-o-map-pin')
            ->info()
            ->actions([
                Action::make('open')
                    ->label('Открыть сделку')
                    ->url(DealResource::getUrl('edit', ['record' => $deal]))
                    ->markAsRead(),
            ])
            ->sendToDatabase($surveyors);
    }
}
