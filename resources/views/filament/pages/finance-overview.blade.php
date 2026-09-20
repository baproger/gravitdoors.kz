{{-- Финансы — обзор. Плитки — как на «Зарплате цеха»; цифры считает FinanceSummary. --}}
<x-filament-panels::page>
    @php
        $s = $this->summary();
        $period = $this->periodLabel();
        $net = $s->net();
    @endphp

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

    <div class="gravit-bento">
        @if ($s->hasAccounts() || $s->debtsCount() > 0)
            {{-- ДДС: деньги на счетах слева, долги справа — как на образце, но из журнала, не руками. --}}
            <div class="gravit-tile">
                <p class="gravit-tile__label">ДДС — деньги и долги на сегодня</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1.5rem; margin-top: 0.75rem;">
                    <div class="gravit-lines">
                        @foreach ($s->accounts() as $account)
                            <div class="gravit-line">
                                <span>{{ $account->name }}</span>
                                <span>{{ \App\Support\Money::format($account->balance()) }}</span>
                            </div>
                        @endforeach
                        <div class="gravit-line gravit-line--total">
                            <span>На счетах</span>
                            <span>{{ \App\Support\Money::format($s->cashBalance() + $s->bankBalance()) }}</span>
                        </div>
                        <div class="gravit-line">
                            <span>Дебиторка — нам должны</span>
                            <span>{{ \App\Support\Money::format($s->receivables()) }}</span>
                        </div>
                    </div>
                    <div class="gravit-lines">
                        @forelse ($s->openDebts()->take(6) as $debt)
                            <div class="gravit-line">
                                <span>{{ $debt->counterparty }}@if ($debt->isOverdue()) <span style="color: rgb(185 28 28); font-weight: 400;">· просрочен</span>@endif</span>
                                <span>{{ \App\Support\Money::format($debt->remaining()) }}</span>
                            </div>
                        @empty
                            <p class="gravit-tile__hint">Долгов нет</p>
                        @endforelse
                        <div class="gravit-line gravit-line--total">
                            <span>Мы должны</span>
                            <span style="color: rgb(185 28 28);">{{ \App\Support\Money::format($s->debts()) }}</span>
                        </div>
                        <div class="gravit-line">
                            <span><a href="{{ \App\Filament\Resources\Debts\DebtResource::getUrl() }}" class="gravit-card__link">Задолженности →</a></span>
                            <span></span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Сумма договоров</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($s->contracts()) }}</p>
            <p class="gravit-tile__hint">{{ $s->contractsCount() }} {{ \App\Support\Plural::choose($s->contractsCount(), 'сделка', 'сделки', 'сделок') }} {{ $period }}, без отменённых</p>
        </div>

        <div class="gravit-tile gravit-tile--third" @if ($s->receivables() > 0) style="border-color: rgb(220 38 38 / 0.25); background: rgb(254 242 242);" @endif>
            <p class="gravit-tile__label">Дебиторка — нам должны</p>
            <p class="gravit-tile__value" @if ($s->receivables() > 0) style="color: rgb(185 28 28);" @endif>{{ \App\Support\Money::format($s->receivables()) }}</p>
            <p class="gravit-tile__hint">{{ $s->receivablesCount() }} {{ \App\Support\Plural::choose($s->receivablesCount(), 'открытая сделка', 'открытые сделки', 'открытых сделок') }} с остатком</p>
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Поступления</p>
            <p class="gravit-tile__value" style="color: rgb(21 128 61);">{{ \App\Support\Money::format($s->receipts()) }}</p>
            <p class="gravit-tile__hint">касса {{ \App\Support\Money::format($s->receiptsCash()) }} · банк {{ \App\Support\Money::format($s->receiptsBank()) }}</p>
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Расходы {{ $period }}</p>
            <p class="gravit-tile__value" style="color: rgb(185 28 28);">− {{ \App\Support\Money::format($s->expenses()) }}</p>
            <div class="gravit-lines" style="margin-top: 0.6rem;">
                @foreach ($s->expenseLines() as $line)
                    <div class="gravit-line">
                        <span>{{ $line['label'] }}</span>
                        <span>{{ \App\Support\Money::format($line['value']) }}</span>
                    </div>
                @endforeach
            </div>
            <p class="gravit-tile__hint" style="margin-top: 0.6rem;">
                @if ($s->hasPayrollSheets())
                    зарплата — по утверждённым <a href="{{ \App\Filament\Pages\SalarySheets::getUrl() }}" class="gravit-card__link">ведомостям</a>; выплаты категории «Зарплата» отдельно не считаются.
                @else
                    {{ $s->isAllTime() ? 'оклады — расчётно по датам приёма' : 'оклады — сотрудники, принятые к концу месяца' }}; точнее — после <a href="{{ \App\Filament\Pages\SalarySheets::getUrl() }}" class="gravit-card__link">ведомости</a>.
                @endif
                Остальные строки — подтверждённые записи раздела <a href="{{ \App\Filament\Resources\Expenses\ExpenseResource::getUrl() }}" class="gravit-card__link">Расходы</a>.
            </p>
        </div>

        <div class="gravit-tile gravit-tile--third" style="background: rgb(15 23 42); color: #fff; border-color: rgb(15 23 42);">
            <p class="gravit-tile__label" style="color: rgb(203 213 225);">Результат {{ $period }}</p>
            <p class="gravit-tile__value" style="color: {{ $net >= 0 ? 'rgb(74 222 128)' : 'rgb(252 165 165)' }};">{{ \App\Support\Money::format($net) }}</p>
            <p class="gravit-tile__hint" style="color: rgb(148 163 184);">поступления − известные расходы</p>
        </div>

        @if ($s->hasAccounts())
            <div class="gravit-tile gravit-tile--third">
                <p class="gravit-tile__label">Касса и банк</p>
                <p class="gravit-tile__value">{{ \App\Support\Money::format($s->cashBalance() + $s->bankBalance()) }}</p>
                <p class="gravit-tile__hint">касса {{ \App\Support\Money::format($s->cashBalance()) }} · банк {{ \App\Support\Money::format($s->bankBalance()) }}{{ $s->isAllTime() ? '' : ' — на конец месяца' }} · <a href="{{ \App\Filament\Pages\CashDesk::getUrl() }}" class="gravit-card__link">журнал →</a></p>
            </div>
        @endif

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Склад по учётной цене</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($s->stockValue()) }}</p>
            <p class="gravit-tile__hint">остатки активных материалов × цена за единицу</p>
        </div>

        <div class="gravit-tile">
            <p class="gravit-tile__label">Разделы</p>
            <div class="gravit-lines" style="margin-top: 0.75rem;">
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Resources\Deals\DealResource::getUrl() }}" class="gravit-card__link">Сделки и счета →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">договоры, предоплата, остаток</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Pages\OverdueDeals::getUrl() }}" class="gravit-card__link">Просроченные →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">кто задерживает сдачу и оплату</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Resources\Expenses\ExpenseResource::getUrl() }}" class="gravit-card__link">Расходы →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">на проверке и оплаченные</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Pages\SalarySheets::getUrl() }}" class="gravit-card__link">Зарплата — ведомость →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">оклад + сдельно + бонусы, выплаты</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Resources\Bonuses\BonusResource::getUrl() }}" class="gravit-card__link">Бонусы →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">начисление и утверждение</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Pages\Payroll::getUrl() }}" class="gravit-card__link">Зарплата цеха →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">сдельно по закрытым этапам</span></span>
                </div>
                <div class="gravit-line">
                    <span><a href="{{ \App\Filament\Resources\StockMovements\StockMovementResource::getUrl() }}" class="gravit-card__link">Движения склада →</a></span>
                    <span><span style="color: var(--gravit-muted); font-weight: 400;">закуп, списания, возвраты</span></span>
                </div>
            </div>
            <p class="gravit-tile__hint" style="margin-top: 0.75rem;">
                Мои расходы, закуп как расход, отчёты — следующие шаги по finance-plan.md.
            </p>
        </div>
    </div>
</x-filament-panels::page>
