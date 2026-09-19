<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\DealEventType;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DealStageVisit;
use App\Models\Role;
use App\Models\User;
use App\Support\DealFieldLabels;
use Filament\Notifications\Notification;

class DealObserver
{
    public function created(Deal $deal): void
    {
        $this->openVisit($deal);

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

    /** Удаление в обход действий панели (массовое, из кода) упирается в то же правило. */
    public function deleting(Deal $deal): bool
    {
        return $deal->canBeDeleted();
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('current_stage_id')) {
            $this->openVisit($deal);
        }

        $this->recordChanges($deal);

        // Дата замера назначена или перенесена — замерщик должен узнать об этом
        // сам, а не из устного «съезди завтра на Абая».
        if ($deal->wasChanged('measured_at') && $deal->measured_at !== null) {
            $this->notifySurveyors($deal);

            DealEvent::record(
                $deal,
                DealEventType::Survey,
                'Замер назначен на '.$deal->measured_at->format('d.m.Y H:i'),
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

    /**
     * Закрыть текущий заход и открыть новый. Журнал ведётся здесь, а не в
     * сервисе: этап меняют и канбан, и карточка, и удаление этапа воронки —
     * наблюдатель ловит все пути разом.
     */
    private function openVisit(Deal $deal): void
    {
        $now = $deal->stage_entered_at ?? now();

        $deal->stageVisits()->whereNull('left_at')->update(['left_at' => $now]);

        if ($deal->current_stage_id === null) {
            return;
        }

        DealStageVisit::create([
            'deal_id' => $deal->id,
            'stage_id' => $deal->current_stage_id,
            'user_id' => auth()->id(),
            'entered_at' => $now,
        ]);
    }

    private function notifySurveyors(Deal $deal): void
    {
        $surveyors = User::query()
            ->where('is_active', true)
            ->whereIn('role', Role::codesWith('does_surveys'))
            ->get();

        if ($surveyors->isEmpty()) {
            return;
        }

        $where = collect([$deal->city, $deal->client_address])->filter()->implode(', ');

        Notification::make()
            ->title('Замер '.$deal->measured_at->format('d.m.Y').' в '.$deal->measured_at->format('H:i'))
            ->body(trim("{$deal->number} · {$deal->clientTitle()}".($where !== '' ? " · {$where}" : '')
                .($deal->client_phone ? " · {$deal->client_phone}" : '')))
            ->icon('heroicon-o-map-pin')
            ->info()
            // Без ссылки на карточку: сделки продаж замерщику и мастеру не открываются,
            // кнопка вела бы на 403. Всё нужное для выезда — в тексте уведомления.
            ->sendToDatabase($surveyors);
    }
}
