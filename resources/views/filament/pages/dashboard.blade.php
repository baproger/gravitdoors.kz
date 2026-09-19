{{--
    Инфопанель: переключатель периода сверху, дальше плитки по смыслу —
    продажи, деньги, цех, склад и люди. Каждый блок появляется только у того,
    кому открыт соответствующий раздел.

    Графики нарисованы inline-SVG: библиотеку ради двух диаграмм в систему
    не тянем, а так они работают и в тёмной теме, и в печати.
--}}
<x-filament-panels::page>
    @php
        $s = $this->stats();
        $p = $this->periodValue();
        $m = fn ($v) => \App\Support\Money::format($v);
        $num = fn ($v) => number_format((float) $v, 0, ',', ' ');
        $money = $s->seesMoney();
        $flow = $s->seesFinance() ? $s->monthlyFlow() : collect();
        $maxFlow = $flow->isNotEmpty() ? max(1, (float) max($flow->max('income'), $flow->max('expense'))) : 1;
    @endphp

    <div class="db">
        {{-- Период --}}
        <div class="db__period">
            @foreach ($this->periodOptions() as $key => $label)
                <button type="button"
                        wire:click="setPeriod(@js($key))"
                        class="db__chip {{ $this->period === $key ? 'db__chip--active' : '' }}">{{ $label }}</button>
            @endforeach

            @if ($this->period === \App\Support\Period::CUSTOM)
                <span class="db__range">
                    <input type="date" wire:model.live="from" class="db__date" aria-label="Начало периода">
                    <span class="db__dash">—</span>
                    <input type="date" wire:model.live="to" class="db__date" aria-label="Конец периода">
                </span>
            @endif

            <span class="db__label">{{ $p->label() }}</span>
        </div>

        {{-- Главные цифры --}}
        <div class="gravit-bento gravit-bento--compact">
            @if ($s->seesSales())
                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">Новых сделок {{ $p->hint() }}</p>
                    <p class="gravit-tile__value">{{ $num($s->newDeals()) }}</p>
                    <p class="gravit-tile__hint">
                        @if ($money) на {{ $m($s->newDealsSum()) }} · средний чек {{ $m($s->averageCheck()) }} @else новые заявки @endif
                    </p>
                </div>

                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">В работе сейчас</p>
                    <p class="gravit-tile__value">{{ $num($s->openDeals()) }}</p>
                    <p class="gravit-tile__hint">{{ $money ? 'портфель '.$m($s->openDealsSum()) : 'открытых сделок' }}</p>
                </div>

                <div class="gravit-tile gravit-tile--third {{ $s->overdueDeals() > 0 ? 'db__tile--alert' : '' }}">
                    <p class="gravit-tile__label">Просрочено</p>
                    <p class="gravit-tile__value" @if ($s->overdueDeals() > 0) style="color: rgb(185 28 28);" @endif>{{ $num($s->overdueDeals()) }}</p>
                    <p class="gravit-tile__hint">
                        @if ($s->blockedShipments() > 0)
                            и {{ $s->blockedShipments() }} с блокировкой отгрузки
                        @else
                            сделок с прошедшим сроком
                        @endif
                    </p>
                </div>
            @endif

            @if ($money)
                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">Поступило {{ $p->hint() }}</p>
                    <p class="gravit-tile__value" style="color: rgb(21 128 61);">{{ $m($s->income()) }}</p>
                    <p class="gravit-tile__hint">
                        наличными {{ $m($s->incomeCash()) }}
                        @if ($s->incomeTrend() !== null)
                            · {{ $s->incomeTrend() >= 0 ? '↑' : '↓' }} {{ abs($s->incomeTrend()) }} % к прошлому отрезку
                        @endif
                    </p>
                </div>

                <div class="gravit-tile gravit-tile--third {{ $s->receivables() > 0 ? 'db__tile--alert' : '' }}">
                    <p class="gravit-tile__label">Нам должны</p>
                    <p class="gravit-tile__value" @if ($s->receivables() > 0) style="color: rgb(185 28 28);" @endif>{{ $m($s->receivables()) }}</p>
                    <p class="gravit-tile__hint">остаток по открытым сделкам</p>
                </div>
            @endif

            @if ($s->seesFinance())
                <div class="gravit-tile gravit-tile--third" style="background: rgb(15 23 42); color: #fff; border-color: rgb(15 23 42);">
                    <p class="gravit-tile__label" style="color: rgb(203 213 225);">Прибыль {{ $p->hint() }}</p>
                    <p class="gravit-tile__value" style="color: {{ $s->profit() >= 0 ? 'rgb(74 222 128)' : 'rgb(252 165 165)' }};">{{ $m($s->profit()) }}</p>
                    <p class="gravit-tile__hint" style="color: rgb(148 163 184);">поступления − подтверждённые расходы</p>
                </div>
            @endif

            @if ($s->seesFactory())
                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">Нарядов в цеху</p>
                    <p class="gravit-tile__value">{{ $num($s->ordersInWork()) }}</p>
                    <p class="gravit-tile__hint">
                        закрыто {{ $p->hint() }}: {{ $num($s->ordersFinished()) }}
                        @if ($s->averageCycleDays() !== null) · цикл {{ $s->averageCycleDays() }} дн. @endif
                    </p>
                </div>

                <div class="gravit-tile gravit-tile--third {{ $s->stuckOrders() > 0 ? 'db__tile--alert' : '' }}">
                    <p class="gravit-tile__label">Застряли на этапе</p>
                    <p class="gravit-tile__value" @if ($s->stuckOrders() > 0) style="color: rgb(185 28 28);" @endif>{{ $num($s->stuckOrders()) }}</p>
                    <p class="gravit-tile__hint">дольше норматива этапа</p>
                </div>

                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">Закрыто этапов</p>
                    <p class="gravit-tile__value">{{ $num($s->stagesClosed()) }}</p>
                    <p class="gravit-tile__hint">{{ $s->seesPayroll() || $money ? 'сдельно '.$m($s->piecework()) : 'операций цеха' }}</p>
                </div>
            @endif

            @if ($s->seesWarehouse())
                <div class="gravit-tile gravit-tile--third {{ $s->lowStockCount() > 0 ? 'db__tile--alert' : '' }}">
                    <p class="gravit-tile__label">Материалы ниже минимума</p>
                    <p class="gravit-tile__value" @if ($s->lowStockCount() > 0) style="color: rgb(185 28 28);" @endif>{{ $num($s->lowStockCount()) }}</p>
                    <p class="gravit-tile__hint">{{ $money ? 'склад на '.$m($s->stockValue()) : 'позиций пора закупать' }}</p>
                </div>
            @endif

            @if ($s->seesPayroll())
                <div class="gravit-tile gravit-tile--third">
                    <p class="gravit-tile__label">К выплате по ведомостям</p>
                    <p class="gravit-tile__value">{{ $m($s->payrollDue()) }}</p>
                    <p class="gravit-tile__hint">
                        сотрудников в штате: {{ $s->activeStaff() }}
                        @if ($s->bonusesPending() > 0) · бонусов на утверждении {{ $m($s->bonusesPending()) }} @endif
                    </p>
                </div>
            @endif
        </div>

        {{-- Графики и разрезы --}}
        <div class="gravit-bento">
            @if ($s->seesFinance() && $flow->isNotEmpty())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Деньги по месяцам</p>
                    <div class="db__chart">
                        @foreach ($flow as $row)
                            <div class="db__bar-group" title="{{ $row['label'] }}: приход {{ $m($row['income']) }}, расход {{ $m($row['expense']) }}">
                                <div class="db__bars">
                                    <span class="db__bar db__bar--in" style="height: {{ max(2, round($row['income'] / $maxFlow * 100)) }}%"></span>
                                    <span class="db__bar db__bar--out" style="height: {{ max(2, round($row['expense'] / $maxFlow * 100)) }}%"></span>
                                </div>
                                <span class="db__bar-label">{{ $row['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="gravit-tile__hint">
                        <i class="gravit-dot gravit-dot--success"></i> приход
                        &nbsp;<i class="gravit-dot gravit-dot--danger"></i> расход · за 12 месяцев
                    </p>
                </div>
            @endif

            @if ($s->seesSales())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Воронка продаж сейчас</p>
                    @php($funnel = $s->funnel())
                    @php($maxStage = max(1, (int) $funnel->max('count')))
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @foreach ($funnel as $stage)
                            <div class="db__funnel">
                                <span class="db__funnel-name">{{ $stage['name'] }}</span>
                                <span class="db__funnel-track">
                                    <span class="db__funnel-fill" style="width: {{ round($stage['count'] / $maxStage * 100) }}%"></span>
                                </span>
                                <span class="db__funnel-value">
                                    {{ $stage['count'] }}@if ($money && $stage['sum'] > 0)<span class="db__funnel-sum">{{ $m($stage['sum']) }}</span>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($s->seesFactory())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Загрузка цеха</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @php($load = $s->workshopLoad())
                        @php($maxLoad = max(1, (int) $load->max('count')))
                        @forelse ($load as $stage)
                            <div class="db__funnel">
                                <span class="db__funnel-name">{{ $stage['name'] }}</span>
                                <span class="db__funnel-track">
                                    <span class="db__funnel-fill db__funnel-fill--factory" style="width: {{ round($stage['count'] / $maxLoad * 100) }}%"></span>
                                </span>
                                <span class="db__funnel-value">{{ $stage['count'] }}</span>
                            </div>
                        @empty
                            <p class="gravit-tile__hint">Этапы цеха не настроены.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($s->seesFinance())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Расходы по статьям {{ $p->hint() }}</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @forelse ($s->expensesByCategory() as $row)
                            <div class="gravit-line"><span>{{ $row['label'] }}</span><span>{{ $m($row['value']) }}</span></div>
                        @empty
                            <p class="gravit-tile__hint">Подтверждённых расходов за период нет.</p>
                        @endforelse
                        <div class="gravit-line gravit-line--total"><span>Итого</span><span>{{ $m($s->expenses()) }}</span></div>
                        <div class="gravit-line"><span>Мы должны</span><span>{{ $m($s->debts()) }}</span></div>
                        <div class="gravit-line"><span>Касса и банк</span><span>{{ $m($s->cashBalance() + $s->bankBalance()) }}</span></div>
                    </div>
                </div>
            @endif

            @if ($s->seesSales() && $s->seesTotals())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Менеджеры {{ $p->hint() }}</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @forelse ($s->byManager() as $row)
                            <div class="gravit-line">
                                <span>{{ $row['name'] }}</span>
                                <span><span style="color: var(--gravit-muted); font-weight: 400;">{{ $row['count'] }} сд.</span>&nbsp;&nbsp;{{ $m($row['sum']) }}</span>
                            </div>
                        @empty
                            <p class="gravit-tile__hint">За период сделок не заводили.</p>
                        @endforelse
                    </div>
                    @if ($s->conversion() !== null)
                        <p class="gravit-tile__hint" style="margin-top: 0.6rem;">
                            Закрыто успешно {{ $s->wonDeals() }}, отказов {{ $s->lostDeals() }} · конверсия {{ $s->conversion() }} %
                        </p>
                    @endif
                </div>
            @endif

            @if ($s->seesSales())
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Откуда пришли {{ $p->hint() }}</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @forelse ($s->sources() as $row)
                            <div class="gravit-line">
                                <span>{{ $row['label'] }}</span>
                                <span>{{ $row['count'] }}@if ($money)<span style="color: var(--gravit-muted); font-weight: 400;">&nbsp;· {{ $m($row['sum']) }}</span>@endif</span>
                            </div>
                        @empty
                            <p class="gravit-tile__hint">Нет заявок за период.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($s->seesFactory() && ($s->seesPayroll() || $money))
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Выработка цеха {{ $p->hint() }}</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @forelse ($s->topWorkers() as $row)
                            <div class="gravit-line">
                                <span>{{ $row['name'] }}</span>
                                <span><span style="color: var(--gravit-muted); font-weight: 400;">{{ $row['stages'] }} эт.</span>&nbsp;&nbsp;{{ $m($row['payout']) }}</span>
                            </div>
                        @empty
                            <p class="gravit-tile__hint">Закрытых этапов за период нет.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            @if ($s->seesWarehouse() && $s->lowStockCount() > 0)
                <div class="gravit-tile gravit-tile--half">
                    <p class="gravit-tile__label">Пора закупать</p>
                    <div class="gravit-lines" style="margin-top: 0.6rem;">
                        @foreach ($s->lowStock() as $material)
                            <div class="gravit-line">
                                <span>{{ $material->name }}</span>
                                <span style="color: rgb(185 28 28);">
                                    {{ rtrim(rtrim((string) $material->quantity, '0'), '.') }} {{ $material->unit->getLabel() }}
                                    <span style="color: var(--gravit-muted); font-weight: 400;">· мин. {{ rtrim(rtrim((string) $material->min_limit, '0'), '.') }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <p class="gravit-tile__hint" style="margin-top: 0.6rem;">
                        <a href="{{ \App\Filament\Resources\MaterialStocks\MaterialStockResource::getUrl() }}" class="gravit-card__link">Открыть склад →</a>
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
