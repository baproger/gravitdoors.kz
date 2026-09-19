{{--
    Инфопанель — одна bento-сетка.

    Плитки разного размера в одной сетке с плотной укладкой (grid-auto-flow:
    dense): у каждой роли свой набор плиток, и сетка сама закрывает дыры, а не
    оставляет пустые места там, где у директора была бы плитка цеха или денег.
    Порядок в разметке задаёт приоритет: сначала «Моя работа», потом главное
    число роли крупно, потом показатели, потом разрезы и списки.

    Цвет плитки — отдел (продажи синие, деньги зелёные, цех янтарный, склад
    серый, люди фиолетовые, личное — индиго). Красная рамка — что-то просрочено.
    Плитка с показателем ведёт в свой раздел, если он пользователю открыт.

    Графики — CSS и inline-SVG: библиотеку ради двух диаграмм в систему не тянем.
--}}
<x-filament-panels::page>
    @php
        $s = $this->stats();
        $p = $this->periodValue();
        $m = fn ($v) => \App\Support\Money::format($v);
        $num = fn ($v) => number_format((float) $v, 0, ',', ' ');
        // Сумма внутри подписи — одним куском: «231 340 ₸» не рвётся на «231» и «340 ₸».
        $mn = fn ($v) => new \Illuminate\Support\HtmlString('<span class="db-nowrap">'.e(\App\Support\Money::format($v)).'</span>');
        $money = $s->seesMoney();
        $flow = $s->seesFinance() ? $s->monthlyFlow() : collect();
        $maxFlow = $flow->isNotEmpty() ? max(1, (float) max($flow->max('income'), $flow->max('expense'))) : 1;

        // Ссылка — только в открытый пользователю раздел: плитка не должна вести на 403.
        // У ресурса первым аргументом getUrl() идёт имя страницы, у страницы — параметры.
        $link = fn (string $page, array $params = []): ?string => match (true) {
            ! $page::canAccess() => null,
            is_subclass_of($page, \Filament\Resources\Resource::class) => $page::getUrl('index', $params),
            default => $page::getUrl($params),
        };
        $url = [
            'deals' => $link(\App\Filament\Resources\Deals\DealResource::class),
            'sales' => $link(\App\Filament\Pages\SalesKanban::class),
            'factory' => $link(\App\Filament\Pages\FactoryKanban::class),
            'overdue' => $link(\App\Filament\Pages\OverdueDeals::class),
            'stuck' => $link(\App\Filament\Pages\OverdueDeals::class, ['mode' => 'stage']),
            'measurements' => $link(\App\Filament\Pages\OverdueDeals::class, ['mode' => 'measurement']),
            'invoices' => $link(\App\Filament\Pages\Invoices::class),
            'incomes' => $link(\App\Filament\Pages\Incomes::class),
            'finance' => $link(\App\Filament\Pages\FinanceOverview::class),
            'expenses' => $link(\App\Filament\Resources\Expenses\ExpenseResource::class),
            'stock' => $link(\App\Filament\Resources\MaterialStocks\MaterialStockResource::class),
            'salary' => $link(\App\Filament\Pages\SalarySheets::class),
            'mySalary' => $link(\App\Filament\Pages\MySalary::class),
        ];

        // Какие плитки видит пользователь и какого они размера — одним списком, в
        // порядке вывода. Отсюда же BentoLayout считает раскладку без дыр: у
        // каждой роли свой набор, и растянуть соседку в пустое место можно, только
        // зная всех. Разметка ниже лишь рисует плитки из $layout.
        $personal = $s->isPersonal();
        $seesMeasurements = $personal && ($s->doesSurveys() || $s->seesSales());
        $today = $seesMeasurements ? $s->myMeasurementsToday() : collect();
        // Просроченные в том же списке: замер вчерашний, а записать его надо —
        // страница «Просроченные» замерщику не открывается.
        $missed = $seesMeasurements ? $s->myMeasurementsOverdueList() : collect();
        $heroMoney = $s->seesFinance() && $flow->isNotEmpty();

        // Нижние списки: по три в ряд (⅓), остаток — парами по половине, чтобы
        // последний ряд не висел одной плиткой.
        $tail = collect([
            'expenses' => $s->seesFinance(),
            'managers' => $s->seesSales() && $s->seesTotals(),
            'sources' => $s->seesSales(),
            'workers' => $s->seesFactoryOverview() && ($s->seesPayroll() || $money),
            'buy' => $s->seesWarehouse() && $s->lowStockCount() > 0,
        ])->filter()->keys()->values();
        $wide = match ($tail->count() % 3) {
            0 => 0,
            1 => $tail->count() >= 4 ? 4 : 1,
            default => 2,
        };
        $tailSize = fn (string $key): string => $tail->search($key) >= $tail->count() - $wide ? 'w' : 'm';

        $plan = collect([
            'myEarnings' => [$personal && $s->worksInShop() && ! $s->seesFactoryOverview(), 'w'],
            'myOrders' => [$personal && $s->worksInShop() && ! $s->seesFactoryOverview(), 'w'],
            'myMeasurements' => [$seesMeasurements, 'w', $today->count() + $missed->count() > 2],
            'myBonus' => [$personal && $s->seesSales(), 's'],
            'income' => [$money, $heroMoney ? 'w' : 's', $heroMoney],
            'newDeals' => [$s->seesSales(), 's'],
            'openDeals' => [$s->seesSales(), 's'],
            'overdue' => [$s->seesSales(), 's'],
            'receivables' => [$money, 's'],
            'profit' => [$s->seesFinance(), 's'],
            'orders' => [$s->seesFactoryOverview(), 's'],
            'stuck' => [$s->seesFactoryOverview(), 's'],
            'stages' => [$s->seesFactoryOverview(), 's'],
            'lowStock' => [$s->seesWarehouse(), 's'],
            'payroll' => [$s->seesPayroll(), 's'],
            'funnel' => [$s->seesSales(), 'w', true],
            'load' => [$s->seesFactoryOverview(), 'w'],
            'expenses' => [$tail->contains('expenses'), $tailSize('expenses')],
            'managers' => [$tail->contains('managers'), $tailSize('managers')],
            'sources' => [$tail->contains('sources'), $tailSize('sources')],
            'workers' => [$tail->contains('workers'), $tailSize('workers')],
            'buy' => [$tail->contains('buy'), $tailSize('buy')],
        ])->filter(fn (array $t): bool => $t[0])
            ->map(fn (array $t): array => ['size' => $t[1], 'tall' => $t[2] ?? false])
            ->all();

        $layout = \App\Support\BentoLayout::fill($plan);
        // Атрибуты плитки: размер для планшета, точный пролёт для широкого экрана.
        $at = fn (string $key): array => [
            'size' => $plan[$key]['size'],
            'tall' => $plan[$key]['tall'],
            'span' => $layout[$key]['span'],
            'rows' => $layout[$key]['rows'],
        ];
        $show = fn (string $key): bool => isset($layout[$key]);
    @endphp

    <div class="db">
        {{-- Период: сегментный переключатель, выбранный отрезок подписан справа --}}
        <div class="db__toolbar">
            <div class="db__period" role="tablist" aria-label="Период">
                @foreach ($this->periodOptions() as $key => $label)
                    <button type="button"
                            role="tab"
                            aria-selected="{{ $this->period === $key ? 'true' : 'false' }}"
                            wire:click="setPeriod(@js($key))"
                            class="db__chip {{ $this->period === $key ? 'db__chip--active' : '' }}">{{ $label }}</button>
                @endforeach
            </div>

            @if ($this->period === \App\Support\Period::CUSTOM)
                <span class="db__range">
                    <input type="date" wire:model.live="from" class="db__date" aria-label="Начало периода">
                    <span class="db__dash">—</span>
                    <input type="date" wire:model.live="to" class="db__date" aria-label="Конец периода">
                </span>
            @endif

            <span class="db__label">
                <x-filament::icon icon="heroicon-m-calendar-days" class="db__label-icon" />
                {{ $p->label() }}
            </span>
        </div>

        <div class="db-bento" wire:loading.class="db-bento--busy">

            {{-- ============ Моя работа — личные цифры сотрудника ============ --}}
            @if ($show('myEarnings'))
                <x-db.tile :layout="$at('myEarnings')" label="Мой заработок {{ $p->hint() }}" tone="mine" icon="heroicon-o-wallet" :href="$url['mySalary']">
                    <p class="db-tile__value db-tile__value--hero">{{ $m($s->myPiecework()) }}</p>
                    <p class="db-tile__foot">Закрыл этапов {{ $p->hint() }}: {{ $num($s->myStagesClosed()) }} · сдельно, подробно — в «Моей зарплате»</p>
                </x-db.tile>
            @endif

            @if ($show('myOrders'))
                <x-db.tile :layout="$at('myOrders')" label="В работе у меня" tone="mine" icon="heroicon-o-wrench-screwdriver"
                           :state="$s->myOrdersInProgress()->isNotEmpty() ? 'accent' : null">
                    <p class="db-tile__value">{{ $num($s->myOrdersInProgress()->count()) }}</p>
                    @if ($s->myOrdersInProgress()->isNotEmpty())
                        <div class="db-tags">
                            @foreach ($s->myOrdersInProgress() as $number)
                                <span class="db-tag">{{ $number }}</span>
                            @endforeach
                        </div>
                    @else
                        <p class="db-tile__foot">Нажмите «Взял» на планшете цеха, чтобы начать этап.</p>
                    @endif
                </x-db.tile>
            @endif

            @if ($show('myMeasurements'))
                <x-db.tile :layout="$at('myMeasurements')" label="Замеры сегодня" tone="mine" icon="heroicon-o-map-pin"
                           :state="$s->myMeasurementsOverdue() > 0 ? 'alert' : null"
                           :href="$s->myMeasurementsOverdue() > 0 ? $url['measurements'] : null">
                    <div class="db-tile__row">
                        <p class="db-tile__value">{{ $num($today->count()) }}</p>
                        <p class="db-tile__aside">
                            @if ($s->myMeasurementsOverdue() > 0)
                                <span class="db-pill db-pill--bad">просрочено {{ $s->myMeasurementsOverdue() }}</span>
                            @endif
                            <span class="db-pill">на неделе ещё {{ $s->myMeasurementsAhead() }}</span>
                        </p>
                    </div>

                    @php($agenda = $missed->concat($today))

                    @if ($agenda->isNotEmpty())
                        <ul class="db-agenda">
                            @foreach ($agenda as $deal)
                                @php($late = $deal->isMeasurementOverdue())
                                <li class="db-agenda__item {{ $late ? 'db-agenda__item--late' : '' }}">
                                    <span class="db-agenda__time">{{ $deal->measured_at->format($late ? 'd.m' : 'H:i') }}</span>
                                    <span class="db-agenda__body">
                                        <span class="db-agenda__who">{{ $deal->clientTitle() }}</span>
                                        <span class="db-agenda__where">{{ collect([$deal->client_address, $deal->client_phone])->filter()->implode(' · ') }}</span>
                                    </span>
                                    <span class="db-agenda__done">
                                        @if ($deal->hasMeasurement())
                                            <span class="db-agenda__size">✓ {{ $deal->measurementSize() }}</span>
                                        @endif
                                        <button type="button" class="db-agenda__action"
                                                wire:click="mountAction('measure', { deal: {{ $deal->id }} })">
                                            {{ $deal->hasMeasurement() ? 'Поправить' : 'Замерял' }}
                                        </button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="db-tile__foot">На сегодня выездов нет.</p>
                    @endif
                </x-db.tile>
            @endif

            @if ($show('myBonus'))
                <x-db.tile :layout="$at('myBonus')" label="Мой бонус {{ $p->hint() }}" tone="mine" icon="heroicon-o-gift" :href="$url['mySalary']">
                    <p class="db-tile__value">{{ $m($s->myBonus()) }}</p>
                    <p class="db-tile__foot">
                        @if ($s->myBonusPending() > 0) на утверждении {{ $mn($s->myBonusPending()) }} @else утверждённые бонусы @endif
                    </p>
                </x-db.tile>
            @endif

            {{-- ============ Деньги: у финансов — крупно, с графиком по месяцам ============ --}}
            @if ($show('income'))
                <x-db.tile :layout="$at('income')" label="Поступило {{ $p->hint() }}" tone="money" icon="heroicon-o-banknotes" :href="$url['incomes']"
                           :class="$heroMoney ? 'db-tile--hero' : null">
                    <div class="db-tile__row">
                        <p class="db-tile__value db-tile__value--good {{ $heroMoney ? 'db-tile__value--hero' : '' }}">{{ $m($s->income()) }}</p>
                        @if ($s->incomeTrend() !== null)
                            <span class="db-trend {{ $s->incomeTrend() >= 0 ? 'db-trend--up' : 'db-trend--down' }}"
                                  title="к прошлому отрезку такой же длины">{{ $s->incomeTrend() >= 0 ? '↑' : '↓' }} {{ abs($s->incomeTrend()) }} %</span>
                        @endif
                    </div>

                    @if ($heroMoney)
                        <p class="db-tile__hint">наличными {{ $mn($s->incomeCash()) }}</p>
                        <div class="db__chart" aria-label="Деньги по месяцам">
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
                        <p class="db-tile__legend">
                            <i class="gravit-dot gravit-dot--success"></i> приход
                            <i class="gravit-dot gravit-dot--danger"></i> расход · Деньги по месяцам, 12 месяцев
                        </p>
                    @else
                        <p class="db-tile__foot">наличными {{ $mn($s->incomeCash()) }}</p>
                    @endif
                </x-db.tile>
            @endif

            {{-- ============ Продажи ============ --}}
            @if ($show('newDeals'))
                <x-db.tile :layout="$at('newDeals')" label="Новых сделок {{ $p->hint() }}" tone="sales" icon="heroicon-o-sparkles" :href="$url['deals']">
                    <p class="db-tile__value">{{ $num($s->newDeals()) }}</p>
                    <p class="db-tile__foot">
                        @if ($money) на {{ $mn($s->newDealsSum()) }} · средний чек {{ $mn($s->averageCheck()) }} @else новые заявки @endif
                    </p>
                </x-db.tile>
            @endif

            @if ($show('openDeals'))
                <x-db.tile :layout="$at('openDeals')" label="В работе сейчас" tone="sales" icon="heroicon-o-briefcase" :href="$url['sales']">
                    <p class="db-tile__value">{{ $num($s->openDeals()) }}</p>
                    <p class="db-tile__foot">@if ($money) портфель {{ $mn($s->openDealsSum()) }} @else открытых сделок @endif</p>
                </x-db.tile>
            @endif

            @if ($show('overdue'))
                <x-db.tile :layout="$at('overdue')" label="Просрочено" tone="sales" icon="heroicon-o-clock"
                           :state="$s->overdueDeals() > 0 ? 'alert' : null" :href="$url['overdue']">
                    <p class="db-tile__value {{ $s->overdueDeals() > 0 ? 'db-tile__value--bad' : '' }}">{{ $num($s->overdueDeals()) }}</p>
                    <p class="db-tile__foot">
                        @if ($s->blockedShipments() > 0)
                            и {{ $s->blockedShipments() }} с блокировкой отгрузки
                        @else
                            сделок с прошедшим сроком
                        @endif
                    </p>
                </x-db.tile>
            @endif

            @if ($show('receivables'))
                <x-db.tile :layout="$at('receivables')" label="Нам должны" tone="money" icon="heroicon-o-receipt-percent"
                           :state="$s->receivables() > 0 ? 'alert' : null" :href="$url['invoices']">
                    <p class="db-tile__value {{ $s->receivables() > 0 ? 'db-tile__value--bad' : '' }}">{{ $m($s->receivables()) }}</p>
                    <p class="db-tile__foot">остаток по открытым сделкам</p>
                </x-db.tile>
            @endif

            @if ($show('profit'))
                <x-db.tile :layout="$at('profit')" label="Прибыль {{ $p->hint() }}" tone="finance" icon="heroicon-o-scale" :href="$url['finance']">
                    <p class="db-tile__value {{ $s->profit() >= 0 ? 'db-tile__value--good' : 'db-tile__value--bad' }}">{{ $m($s->profit()) }}</p>
                    <p class="db-tile__foot">поступления − подтверждённые расходы</p>
                </x-db.tile>
            @endif

            {{-- ============ Цех ============ --}}
            @if ($show('orders'))
                <x-db.tile :layout="$at('orders')" label="Нарядов в цеху" tone="factory" icon="heroicon-o-cog-6-tooth" :href="$url['factory']">
                    <p class="db-tile__value">{{ $num($s->ordersInWork()) }}</p>
                    <p class="db-tile__foot">
                        закрыто {{ $p->hint() }}: {{ $num($s->ordersFinished()) }}
                        @if ($s->averageCycleDays() !== null) · цикл {{ $s->averageCycleDays() }} дн. @endif
                    </p>
                </x-db.tile>
            @endif

            @if ($show('stuck'))
                <x-db.tile :layout="$at('stuck')" label="Застряли на этапе" tone="factory" icon="heroicon-o-exclamation-triangle"
                           :state="$s->stuckOrders() > 0 ? 'alert' : null" :href="$url['stuck']">
                    <p class="db-tile__value {{ $s->stuckOrders() > 0 ? 'db-tile__value--bad' : '' }}">{{ $num($s->stuckOrders()) }}</p>
                    <p class="db-tile__foot">дольше норматива этапа</p>
                </x-db.tile>
            @endif

            @if ($show('stages'))
                <x-db.tile :layout="$at('stages')" label="Закрыто этапов" tone="factory" icon="heroicon-o-check-badge">
                    <p class="db-tile__value">{{ $num($s->stagesClosed()) }}</p>
                    <p class="db-tile__foot">@if ($s->seesPayroll() || $money) сдельно {{ $mn($s->piecework()) }} @else операций цеха @endif</p>
                </x-db.tile>
            @endif

            {{-- ============ Склад и люди ============ --}}
            @if ($show('lowStock'))
                <x-db.tile :layout="$at('lowStock')" label="Материалы ниже минимума" tone="stock" icon="heroicon-o-archive-box"
                           :state="$s->lowStockCount() > 0 ? 'alert' : null" :href="$url['stock']">
                    <p class="db-tile__value {{ $s->lowStockCount() > 0 ? 'db-tile__value--bad' : '' }}">{{ $num($s->lowStockCount()) }}</p>
                    <p class="db-tile__foot">@if ($money) склад на {{ $mn($s->stockValue()) }} @else позиций пора закупать @endif</p>
                </x-db.tile>
            @endif

            @if ($show('payroll'))
                <x-db.tile :layout="$at('payroll')" label="К выплате по ведомостям" tone="people" icon="heroicon-o-user-group" :href="$url['salary']">
                    <p class="db-tile__value">{{ $m($s->payrollDue()) }}</p>
                    <p class="db-tile__foot">
                        сотрудников в штате: {{ $s->activeStaff() }}
                        @if ($s->bonusesPending() > 0) · бонусов на утверждении {{ $mn($s->bonusesPending()) }} @endif
                    </p>
                </x-db.tile>
            @endif

            {{-- ============ Разрезы и списки ============ --}}
            @if ($show('funnel'))
                @php($funnel = $s->funnel())
                @php($maxStage = max(1, (int) $funnel->max('count')))
                <x-db.tile :layout="$at('funnel')" label="Воронка продаж сейчас" tone="sales" icon="heroicon-o-funnel" :href="$url['sales']">
                    <div class="db-tile__body">
                        @foreach ($funnel as $stage)
                            <div class="db__funnel">
                                <span class="db__funnel-name">{{ $stage['name'] }}</span>
                                <span class="db__funnel-track">
                                    <span class="db__funnel-fill" style="width: {{ round($stage['count'] / $maxStage * 100) }}%"></span>
                                </span>
                                <span class="db__funnel-value">
                                    {{ $stage['count'] }}@if ($money && $stage['sum'] > 0)<span class="db__funnel-sum">· {{ $m($stage['sum']) }}</span>@endif
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-db.tile>
            @endif

            @if ($show('load'))
                @php($load = $s->workshopLoad())
                @php($maxLoad = max(1, (int) $load->max('count')))
                <x-db.tile :layout="$at('load')" label="Загрузка цеха" tone="factory" icon="heroicon-o-chart-bar" :href="$url['factory']">
                    {{-- Этапов цеха 13: в две колонки список не растягивает плитку и соседей по строке --}}
                    <div class="db-tile__body db-columns">
                        @forelse ($load as $stage)
                            <div class="db__funnel">
                                <span class="db__funnel-name">{{ $stage['name'] }}</span>
                                <span class="db__funnel-track">
                                    <span class="db__funnel-fill db__funnel-fill--factory" style="width: {{ round($stage['count'] / $maxLoad * 100) }}%"></span>
                                </span>
                                <span class="db__funnel-value">{{ $stage['count'] }}</span>
                            </div>
                        @empty
                            <p class="db-tile__foot">Этапы цеха не настроены.</p>
                        @endforelse
                    </div>
                </x-db.tile>
            @endif

            @if ($show('expenses'))
                <x-db.tile :layout="$at('expenses')" label="Расходы по статьям {{ $p->hint() }}" tone="money" icon="heroicon-o-arrow-trending-down" :href="$url['expenses']">
                    <div class="db-tile__body gravit-lines">
                        @forelse ($s->expensesByCategory() as $row)
                            <div class="gravit-line"><span>{{ $row['label'] }}</span><span>{{ $m($row['value']) }}</span></div>
                        @empty
                            <p class="db-tile__foot">Подтверждённых расходов за период нет.</p>
                        @endforelse
                        <div class="gravit-line gravit-line--total"><span>Итого</span><span>{{ $m($s->expenses()) }}</span></div>
                        <div class="gravit-line"><span>Мы должны</span><span>{{ $m($s->debts()) }}</span></div>
                        <div class="gravit-line"><span>Касса и банк</span><span>{{ $m($s->cashBalance() + $s->bankBalance()) }}</span></div>
                    </div>
                </x-db.tile>
            @endif

            @if ($show('managers'))
                <x-db.tile :layout="$at('managers')" label="Менеджеры {{ $p->hint() }}" tone="sales" icon="heroicon-o-trophy">
                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @forelse ($s->byManager() as $i => $row)
                            <div class="gravit-line">
                                <span><span class="db-rank">{{ $i + 1 }}</span>{{ $row['name'] }}</span>
                                <span><span class="db-muted">{{ $row['count'] }} сд.</span>&nbsp;&nbsp;{{ $m($row['sum']) }}</span>
                            </div>
                        @empty
                            <p class="db-tile__foot">За период сделок не заводили.</p>
                        @endforelse
                    </div>
                    @if ($s->conversion() !== null)
                        <p class="db-tile__foot">Закрыто успешно {{ $s->wonDeals() }}, отказов {{ $s->lostDeals() }} · конверсия {{ $s->conversion() }} %</p>
                    @endif
                </x-db.tile>
            @endif

            @if ($show('sources'))
                <x-db.tile :layout="$at('sources')" label="Откуда пришли {{ $p->hint() }}" tone="sales" icon="heroicon-o-megaphone">
                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @forelse ($s->sources() as $row)
                            <div class="gravit-line">
                                <span>{{ $row['label'] }}</span>
                                <span>{{ $row['count'] }}@if ($money)<span class="db-muted">&nbsp;· {{ $m($row['sum']) }}</span>@endif</span>
                            </div>
                        @empty
                            <p class="db-tile__foot">Нет заявок за период.</p>
                        @endforelse
                    </div>
                </x-db.tile>
            @endif

            @if ($show('workers'))
                <x-db.tile :layout="$at('workers')" label="Выработка цеха {{ $p->hint() }}" tone="factory" icon="heroicon-o-bolt">
                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @forelse ($s->topWorkers() as $i => $row)
                            <div class="gravit-line">
                                <span><span class="db-rank">{{ $i + 1 }}</span>{{ $row['name'] }}</span>
                                <span><span class="db-muted">{{ $row['stages'] }} эт.</span>&nbsp;&nbsp;{{ $m($row['payout']) }}</span>
                            </div>
                        @empty
                            <p class="db-tile__foot">Закрытых этапов за период нет.</p>
                        @endforelse
                    </div>
                </x-db.tile>
            @endif

            @if ($show('buy'))
                <x-db.tile :layout="$at('buy')" label="Пора закупать" tone="stock" icon="heroicon-o-shopping-cart" state="alert" :href="$url['stock']">
                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @foreach ($s->lowStock() as $material)
                            <div class="gravit-line">
                                <span>{{ $material->name }}</span>
                                <span class="db-bad">
                                    {{ rtrim(rtrim((string) $material->quantity, '0'), '.') }} {{ $material->unit->getLabel() }}
                                    <span class="db-muted">· мин. {{ rtrim(rtrim((string) $material->min_limit, '0'), '.') }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </x-db.tile>
            @endif
        </div>
    </div>
</x-filament-panels::page>
