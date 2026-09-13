<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DealEventType;
use App\Enums\UserRole;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use App\Support\DealFieldLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class DealObserver
{
    public function created(Deal $deal): void
    {
        DealEvent::record(
            $deal,
            DealEventType::Created,
            $deal->isFactoryOrder()
                ? "Открыт производственный наряд {$deal->number}"
                : "Создана сделка {$deal->number}",
        );

        if ($deal->measured_at !== null) {
            $this->notifySurveyors($deal);
        }
    }

    public function updated(Deal $deal): void
    {
        $this->recordChanges($deal);

        // Дата замера назначена или перенесена — замерщик должен узнать об этом
        // сам, а не из устного «съезди завтра на Абая».
        if ($deal->wasChanged('measured_at') && $deal->measured_at !== null) {
            $this->notifySurveyors($deal);

            DealEvent::record(
                $deal,
                DealEventType::Survey,
                'Замер назначен на '.$deal->measured_at->format('d.m.Y'),
            );
        }

        if ($deal->wasChanged('documents') && filled($deal->documents)) {
            DealEvent::record(
                $deal,
                DealEventType::Document,
                'Загружены документы: '.count($deal->documents).' шт',
            );
        }

    }

    /**
     * Правки карточки одной записью: менеджер обычно меняет несколько полей за
     * раз, и отдельная строка на каждое поле превратила бы ленту в шум.
     */
    private function recordChanges(Deal $deal): void
    {
        $changes = [];

        foreach ($deal->getChanges() as $field => $new) {
            if (! DealFieldLabels::isTracked($field)) {
                continue;
            }

            $changes[$field] = [
                'label' => DealFieldLabels::label($field),
                'from' => DealFieldLabels::value($field, $deal->getOriginal($field)),
                'to' => DealFieldLabels::value($field, $deal->{$field}),
            ];
        }

        if ($changes === []) {
            return;
        }

        $names = collect($changes)->pluck('label')->take(4)->implode(', ');
        $more = count($changes) > 4 ? ' и ещё '.(count($changes) - 4) : '';

        DealEvent::record($deal, DealEventType::Updated, "Изменено: {$names}{$more}", changes: $changes);
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
