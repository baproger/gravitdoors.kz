{{--
    Финансы — обзор в bento-сетке.

    Та же система, что на инфопанели: список плиток с размерами, BentoLayout
    раскладывает их без дыр, <x-db.tile> рисует. Своей сетки здесь нет
    намеренно — два похожих, но разных обзора расходились бы в мелочах на
    каждой правке, а выглядят они рядом в одном меню.

    Порядок в $plan — порядок чтения: сначала итог, потом из чего он сложился,
    потом на чём стоим (счета, долги, склад) и куда идти дальше.
--}}
<x-filament-panels::page>
    @php
        $s = $this->summary();
        $period = $this->periodLabel();
        $net = $s->net();

        $m = fn ($v) => \App\Support\Money::format($v);
        $mn = fn ($v) => new \Illuminate\Support\HtmlString('<span class="db-nowrap">'.e(\App\Support\Money::format($v)).'</span>');
        $plural = fn (int $n, string ...$forms) => \App\Support\Plural::choose($n, ...$forms);

        $hasDebts = $s->debtsCount() > 0;
        $overdueDebt = $hasDebts && $s->openDebts()->contains(fn ($debt): bool => $debt->isOverdue());

        $plan = collect([
            'net' => [true, 'w'],
            'receipts' => [true, 's'],
            'receivables' => [true, 's'],
            'expenses' => [true, 'w', true],
            'contracts' => [true, 's'],
            'stock' => [true, 's'],
            'cash' => [$s->hasAccounts(), 'w'],
            'debts' => [$hasDebts, 'w'],
            'links' => [true, 'w'],
        ])->filter(fn (array $t): bool => $t[0])
            ->map(fn (array $t): array => ['size' => $t[1], 'tall' => $t[2] ?? false])
            ->all();

        $layout = \App\Support\BentoLayout::fill($plan);
        $at = fn (string $key): array => [
            'size' => $plan[$key]['size'],
            'tall' => $plan[$key]['tall'],
            'span' => $layout[$key]['span'],
            'rows' => $layout[$key]['rows'],
        ];
        $show = fn (string $key): bool => isset($layout[$key]);

        $url = [
            'cash' => \App\Filament\Pages\CashDesk::getUrl(),
            'debts' => \App\Filament\Resources\Debts\DebtResource::getUrl(),
            'expenses' => \App\Filament\Resources\Expenses\ExpenseResource::getUrl(),
            'deals' => \App\Filament\Resources\Deals\DealResource::getUrl(),
            'overdue' => \App\Filament\Pages\OverdueDeals::getUrl(),
            'salary' => \App\Filament\Pages\SalarySheets::getUrl(),
            'bonuses' => \App\Filament\Resources\Bonuses\BonusResource::getUrl(),
            'payroll' => \App\Filament\Pages\Payroll::getUrl(),
            'stock' => \App\Filament\Resources\StockMovements\StockMovementResource::getUrl(),
        ];
    @endphp

    <div class="db">
        <div class="gravit-toolbar">
            <select wire:model.live="month" class="gravit-select">
                @foreach ($this->monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>

            <label class="gravit-field">
                <span class="gravit-field__label">Или период с</span>
                <input type="date" wire:model.live="from" class="gravit-select" />
            </label>

            <label class="gravit-field">
                <span class="gravit-field__label">по</span>
                <input type="date" wire:model.live="to" class="gravit-select" />
            </label>

            @if (filled($from) || filled($to))
                <button type="button" wire:click="resetPeriod" class="gravit-filter-reset">Вернуть месяц</button>
            @endif
        </div>

        <div class="db-bento" wire:loading.class="db-bento--busy">
            {{-- Итог первым и крупно: ради этой цифры страницу и открывают. --}}
            @if ($show('net'))
                <x-db.tile
                    :layout="$at('net')"
                    label="Результат {{ $period }}"
                    tone="finance"
                    icon="heroicon-o-scale"
                    :state="$net < 0 ? 'alert' : 'accent'"
                >
                    <p class="db-tile__value db-tile__value--hero {{ $net >= 0 ? 'db-tile__value--good' : 'db-tile__value--bad' }}">{{ $m($net) }}</p>
                    <p class="db-tile__foot">
                        поступления {{ $mn($s->receipts()) }} − известные расходы {{ $mn($s->expenses()) }}
                    </p>
                </x-db.tile>
            @endif

            @if ($show('receipts'))
                <x-db.tile :layout="$at('receipts')" label="Поступления {{ $period }}" tone="money" icon="heroicon-o-arrow-trending-up">
                    <p class="db-tile__value db-tile__value--good">{{ $m($s->receipts()) }}</p>
                    <p class="db-tile__foot">касса {{ $mn($s->receiptsCash()) }} · банк {{ $mn($s->receiptsBank()) }}</p>
                </x-db.tile>
            @endif

            @if ($show('receivables'))
                <x-db.tile
                    :layout="$at('receivables')"
                    label="Нам должны"
                    tone="money"
                    icon="heroicon-o-clock"
                    :state="$s->receivables() > 0 ? 'alert' : null"
                    :href="$url['deals']"
                >
                    <p class="db-tile__value {{ $s->receivables() > 0 ? 'db-tile__value--bad' : '' }}">{{ $m($s->receivables()) }}</p>
                    <p class="db-tile__foot">
                        {{ $s->receivablesCount() }} {{ $plural($s->receivablesCount(), 'открытая сделка', 'открытые сделки', 'открытых сделок') }} с остатком
                    </p>
                </x-db.tile>
            @endif

            {{-- Расходы высокие: под цифрой разбивка по статьям, ради неё и заходят. --}}
            @if ($show('expenses'))
                <x-db.tile :layout="$at('expenses')" label="Расходы {{ $period }}" tone="finance" icon="heroicon-o-arrow-trending-down">
                    <p class="db-tile__value db-tile__value--bad">− {{ $m($s->expenses()) }}</p>

                    <div class="db-tile__body gravit-lines">
                        @forelse ($s->expenseLines() as $line)
                            <div class="gravit-line">
                                <span>{{ $line['label'] }}</span>
                                <span>{{ $m($line['value']) }}</span>
                            </div>
                        @empty
                            <p class="db-tile__foot">За период расходов не подтверждали.</p>
                        @endforelse
                    </div>

                    <p class="db-tile__foot">
                        @if ($s->hasPayrollSheets())
                            Зарплата — по утверждённым ведомостям; выплаты категории «Зарплата» отдельно не считаются.
                        @else
                            {{ $s->isAllTime() ? 'Оклады — расчётно по датам приёма' : 'Оклады — сотрудники, принятые к концу месяца' }}; точнее — после ведомости.
                        @endif
                    </p>
                </x-db.tile>
            @endif

            @if ($show('contracts'))
                <x-db.tile :layout="$at('contracts')" label="Сумма договоров" tone="sales" icon="heroicon-o-document-text" :href="$url['deals']">
                    <p class="db-tile__value">{{ $m($s->contracts()) }}</p>
                    <p class="db-tile__foot">
                        {{ $s->contractsCount() }} {{ $plural($s->contractsCount(), 'сделка', 'сделки', 'сделок') }} {{ $period }}, без отменённых
                    </p>
                </x-db.tile>
            @endif

            @if ($show('stock'))
                <x-db.tile :layout="$at('stock')" label="Склад по учётной цене" tone="stock" icon="heroicon-o-cube" :href="$url['stock']">
                    <p class="db-tile__value">{{ $m($s->stockValue()) }}</p>
                    <p class="db-tile__foot">остатки активных материалов × цена за единицу</p>
                </x-db.tile>
            @endif

            {{-- Счета: сколько денег есть прямо сейчас, по каждому счёту отдельно. --}}
            @if ($show('cash'))
                <x-db.tile :layout="$at('cash')" label="Касса и банк" tone="money" icon="heroicon-o-building-library" :href="$url['cash']">
                    <div class="db-tile__row">
                        <p class="db-tile__value">{{ $m($s->cashBalance() + $s->bankBalance()) }}</p>
                        <p class="db-tile__aside">
                            <span class="db-pill">касса {{ $m($s->cashBalance()) }}</span>
                            <span class="db-pill">банк {{ $m($s->bankBalance()) }}</span>
                        </p>
                    </div>

                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @foreach ($s->accounts() as $account)
                            <div class="gravit-line">
                                <span>{{ $account->name }}</span>
                                <span>{{ $m($account->balance()) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="db-tile__foot">{{ $s->isAllTime() ? 'Остатки на сегодня' : 'Остатки на конец периода' }} по журналу кассы и банка.</p>
                </x-db.tile>
            @endif

            {{-- Долги показываем, только если они есть: пустая плитка «Мы должны 0» — шум. --}}
            @if ($show('debts'))
                <x-db.tile
                    :layout="$at('debts')"
                    label="Мы должны"
                    tone="finance"
                    icon="heroicon-o-exclamation-triangle"
                    :state="$overdueDebt ? 'alert' : null"
                    :href="$url['debts']"
                >
                    <p class="db-tile__value db-tile__value--bad">{{ $m($s->debts()) }}</p>

                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        @foreach ($s->openDebts()->take(6) as $debt)
                            <div class="gravit-line">
                                <span>
                                    {{ $debt->counterparty }}
                                    @if ($debt->isOverdue())
                                        <span class="db-bad">· просрочен</span>
                                    @endif
                                </span>
                                <span>{{ $m($debt->remaining()) }}</span>
                            </div>
                        @endforeach
                    </div>

                    <p class="db-tile__foot">
                        {{ $s->debtsCount() }} {{ $plural($s->debtsCount(), 'открытый долг', 'открытых долга', 'открытых долгов') }} поставщикам и подрядчикам
                    </p>
                </x-db.tile>
            @endif

            {{-- Разделы: плитка целиком ссылкой быть не может — ссылок внутри много. --}}
            @if ($show('links'))
                <x-db.tile :layout="$at('links')" label="Куда идти дальше" tone="people" icon="heroicon-o-squares-2x2">
                    <div class="db-tile__body gravit-lines db-columns db-columns--wide">
                        <div class="gravit-line">
                            <span><a href="{{ $url['deals'] }}" class="gravit-card__link">Сделки и счета →</a></span>
                            <span class="db-muted">договоры, предоплата, остаток</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['overdue'] }}" class="gravit-card__link">Просроченные →</a></span>
                            <span class="db-muted">кто задерживает сдачу и оплату</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['expenses'] }}" class="gravit-card__link">Расходы →</a></span>
                            <span class="db-muted">на проверке и оплаченные</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['salary'] }}" class="gravit-card__link">Зарплата — ведомость →</a></span>
                            <span class="db-muted">оклад, сдельно, бонусы, выплаты</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['bonuses'] }}" class="gravit-card__link">Бонусы →</a></span>
                            <span class="db-muted">начисление и утверждение</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['payroll'] }}" class="gravit-card__link">Зарплата цеха →</a></span>
                            <span class="db-muted">сдельно по закрытым этапам</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ $url['stock'] }}" class="gravit-card__link">Движения склада →</a></span>
                            <span class="db-muted">закуп, списания, возвраты</span>
                        </div>
                    </div>
                </x-db.tile>
            @endif
        </div>
    </div>
</x-filament-panels::page>
