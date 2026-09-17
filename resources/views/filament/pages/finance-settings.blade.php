<x-filament-panels::page>
    <div class="gravit-bento">
        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Бонус менеджеру со сделки</p>
            <p class="gravit-tile__value">{{ rtrim(rtrim(number_format($this->percent(), 2, '.', ''), '0'), '.') }} %</p>
            <p class="gravit-tile__hint">
                @if ($this->percent() > 0)
                    со сделки на 1 000 000 {{ config('gravit.currency.symbol') }} — {{ \App\Support\Money::format(1_000_000 * $this->percent() / 100) }}
                @else
                    автобонус выключен
                @endif
            </p>
        </div>

        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Утверждение</p>
            <p class="gravit-tile__value" style="font-size: 1.25rem;">{{ $this->autoApprove() ? 'автоматически' : 'администратором' }}</p>
            <p class="gravit-tile__hint">{{ $this->autoApprove() ? 'бонус сразу идёт в ведомость' : 'бонус появляется в «Бонусы → На утверждении»' }}</p>
        </div>

        <div class="gravit-tile">
            <p class="gravit-tile__label">Как это работает</p>
            <div class="gravit-lines" style="margin-top: 0.75rem;">
                <div class="gravit-line"><span>Когда начисляется</span><span>при переводе сделки на завершающий этап</span></div>
                <div class="gravit-line"><span>От чего считается</span><span>сумма сделки на момент закрытия</span></div>
                <div class="gravit-line"><span>Кому</span><span>ответственному менеджеру сделки</span></div>
                <div class="gravit-line"><span>Персональная ставка</span><span>карточка сотрудника → «Условия работы»</span></div>
                <div class="gravit-line"><span>Сколько раз</span><span>один бонус на сделку</span></div>
                <div class="gravit-line"><span>Куда попадает</span><span>ведомость за месяц закрытия после утверждения</span></div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
