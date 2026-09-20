{{--
    Канбан обеих воронок.

    Перетаскивание — нативный HTML5 drag & drop на Alpine: внешняя библиотека
    сюда не тянется, весь нужный функционал это dragstart / dragover / drop.
    На тач-экранах такие события не приходят вовсе, поэтому у каждой карточки
    есть кнопки «← →» на соседние этапы: на телефоне это единственный рабочий
    способ двигать сделку, а на десктопе — просто быстрее мышки.
--}}
<x-filament-panels::page>
    @php
        $stages = $this->getStages()->values();
        $symbol = $this->getCurrencySymbol();
        $showMoney = $this->canSeeMoney();
        // Итог по колонке — сводный показатель: менеджеру видны суммы своих карточек, но не воронки.
        $showTotals = $this->canSeeTotals();
    @endphp

    <div
        x-data="{
            draggingId: null,
            overStage: null,
            start(event, dealId) {
                this.draggingId = dealId
                event.dataTransfer.effectAllowed = 'move'
                event.dataTransfer.setData('text/plain', dealId)
            },
            drop(stageId) {
                const dealId = this.draggingId
                this.draggingId = null
                this.overStage = null
                if (dealId) {
                    $wire.moveDeal(dealId, stageId)
                }
            },
        }"
        class="gravit-board"
    >
        {{-- Фильтры --}}
        @php
            $criteria = $this->criteria();
            $activeFilters = $criteria->activeCount();
        @endphp

        <div class="gravit-toolbar">
            <label class="gravit-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="gravit-search__icon" />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Номер, клиент, компания, телефон…"
                    class="gravit-search__input"
                />
            </label>

            @if (filled($managers = $this->getManagerOptions()))
                <select wire:model.live="managerId" class="gravit-select">
                    <option value="">Все менеджеры</option>
                    @foreach ($managers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            <button
                type="button"
                wire:click="$toggle('filtersOpen')"
                class="gravit-filter-toggle @if ($filtersOpen) gravit-filter-toggle--open @endif"
                aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
            >
                <x-filament::icon icon="heroicon-m-adjustments-horizontal" class="gravit-filter-toggle__icon" />
                <span>Фильтры</span>
                @if ($activeFilters > 0)
                    <span class="gravit-filter-toggle__count">{{ $activeFilters }}</span>
                @endif
            </button>

            @if ($activeFilters > 0)
                <button type="button" wire:click="resetFilters" class="gravit-filter-reset">Сбросить</button>
            @endif
        </div>

        @if ($filtersOpen)
            <div class="gravit-filters">
                <label class="gravit-field">
                    <span class="gravit-field__label">Город</span>
                    <select wire:model.live="city" class="gravit-select">
                        <option value="">Любой</option>
                        @foreach ($this->getCityOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="gravit-field">
                    <span class="gravit-field__label">Источник</span>
                    <select wire:model.live="source" class="gravit-select">
                        <option value="">Любой</option>
                        @foreach ($this->getSourceOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="gravit-field">
                    <span class="gravit-field__label">Срок сдачи с</span>
                    <input type="date" wire:model.live="dueFrom" class="gravit-select" />
                </label>

                <label class="gravit-field">
                    <span class="gravit-field__label">Срок сдачи по</span>
                    <input type="date" wire:model.live="dueUntil" class="gravit-select" />
                </label>

                @if ($this->canSeeMoney())
                    <label class="gravit-field">
                        <span class="gravit-field__label">Оплата</span>
                        <select wire:model.live="payment" class="gravit-select">
                            <option value="">Любая</option>
                            <option value="due">Есть остаток</option>
                            <option value="paid">Оплачено полностью</option>
                        </select>
                    </label>
                @endif

                <label class="gravit-field gravit-field--check">
                    <input type="checkbox" wire:model.live="overdueOnly" class="gravit-checkbox" />
                    <span>Только просроченные</span>
                </label>
            </div>
        @endif

        @if ($activeFilters > 0)
            <p class="gravit-filters__summary">
                Показаны только: {{ implode(' · ', $criteria->labels()) }}
            </p>
        @endif

        {{-- Колонки --}}
        <div class="gravit-columns">
            @forelse ($stages as $index => $stage)
                @php
                    $previousStage = $stages->get($index - 1);
                    $nextStage = $stages->get($index + 1);
                    $sum = $stage->deals->sum(fn ($deal) => (float) $deal->total_price);
                    $total = $stage->deals_count ?? $stage->deals->count();
                    $hidden = max(0, $total - $stage->deals->count());
                @endphp

                <section
                    class="gravit-column"
                    data-color="{{ $stage->color }}"
                    :class="overStage === {{ $stage->id }} && 'gravit-column--over'"
                    @dragover.prevent="overStage = {{ $stage->id }}"
                    @dragleave="overStage === {{ $stage->id }} && (overStage = null)"
                    @drop.prevent="drop({{ $stage->id }})"
                >
                    <header class="gravit-column__head">
                        <div class="gravit-column__title">
                            @if ($stage->icon)
                                <x-filament::icon :icon="$stage->icon" class="gravit-column__icon" />
                            @endif
                            <span>{{ $stage->name }}</span>
                            <span class="gravit-chip">{{ $total }}</span>
                        </div>

                        <div class="gravit-column__meta">
                            @if ($showTotals && $sum > 0)
                                <span>{{ number_format($sum, 0, ',', ' ') }} {{ $symbol }}</span>
                            @endif
                            @if ($stage->triggers_production)
                                <span class="gravit-flag gravit-flag--warning" title="Вход на этап создаёт наряд на заводе">→ завод</span>
                            @endif
                            @if ($stage->completes_production)
                                <span class="gravit-flag gravit-flag--success" title="Закрытие этапа переводит сделку в «Готово к отгрузке»">завод →</span>
                            @endif
                        </div>
                    </header>

                    <div class="gravit-column__body">
                        @forelse ($stage->deals as $deal)
                            @php($configs = $deal->configurations())
                            {{-- Сделку, чей наряд ещё в цеху, ведёт завод: без стрелок и перетаскивания. --}}
                            @php($waiting = $deal->isFactoryOrder() ? null : $deal->activeProductionOrder())
                            {{-- Права решает политика: рабочему доска видна, но двигать и открывать карточки он не может. --}}
                            @php($canMove = auth()->user()?->can('move', $deal) ?? false)
                            @php($canOpen = auth()->user()?->can('view', $deal) ?? false)

                            <article
                                draggable="{{ $waiting || ! $canMove ? 'false' : 'true' }}"
                                @dragstart="start($event, {{ $deal->id }})"
                                @dragend="draggingId = null; overStage = null"
                                :class="draggingId === {{ $deal->id }} && 'gravit-card--dragging'"
                                class="gravit-card {{ $deal->isOverdue() || $deal->isMeasurementOverdue() ? 'gravit-card--overdue' : '' }}"
                                wire:key="deal-{{ $deal->id }}"
                            >
                                <div class="gravit-card__top">
                                    <span class="gravit-card__number">{{ $deal->number }}</span>
                                    @if ($deal->hours_on_stage >= 1)
                                        @php($visits = $deal->visitsOnCurrentStage())
                                        <span @class(['gravit-card__timer', 'gravit-card__timer--late' => $deal->isStageOverdue()])
                                              title="{{ ($visits > 1 ? $visits.'-й заход, всего на этапе '.$deal->hours_on_stage.' ч. ' : 'На этапе '.$deal->hours_on_stage.' ч. ').($deal->isStageOverdue() ? 'Дольше норматива на '.$deal->stageOverdueHours().' ч' : '') }}">
                                            <x-filament::icon icon="heroicon-m-clock" class="gravit-card__timer-icon" />
                                            <span>{{ $deal->hours_on_stage }} ч</span>
                                            @if ($visits > 1)
                                                <span class="gravit-card__timer-visits" aria-label="{{ $visits }}-й заход">↺{{ $visits }}</span>
                                            @endif
                                        </span>
                                    @endif
                                </div>

                                <h3 class="gravit-card__title">{{ $deal->title }}</h3>

                                <p class="gravit-card__client">
                                    {{ $deal->clientTitle() }}
                                    @if ($deal->client_phone)
                                        <a href="tel:{{ preg_replace('/\D+/', '', $deal->client_phone) }}"
                                           class="gravit-card__phone">{{ $deal->client_phone }}</a>
                                    @endif
                                </p>

                                @if ($configs->isNotEmpty())
                                    <p class="gravit-card__spec">
                                        @if ($configs->count() === 1)
                                            {{ $configs->first()->productName() }} · {{ $configs->first()->humanSize() }} · {{ $configs->first()->quantity }} шт
                                        @else
                                            {{ $configs->count() }} {{ \App\Support\Plural::choose($configs->count(), 'позиция', 'позиции', 'позиций') }} · {{ $deal->doorsCount() }} шт
                                        @endif
                                    </p>
                                @endif

                                <div class="gravit-card__bottom">
                                    @if ($showMoney && (float) $deal->total_price > 0)
                                        <span class="gravit-card__price">
                                            {{ number_format((float) $deal->total_price, 0, ',', ' ') }} {{ $symbol }}
                                        </span>
                                    @endif

                                    @if ($deal->due_date)
                                        <span @class([
                                            'gravit-card__due',
                                            'gravit-card__due--late' => $deal->isOverdue(),
                                        ]) title="{{ $deal->isOverdue() ? 'Срок сдачи прошёл' : 'Срок сдачи' }}">
                                            {{ $deal->isOverdue() ? 'просрочено '.$deal->overdueDays().' дн.' : $deal->due_date->format('d.m') }}
                                        </span>
                                    @endif
                                </div>

                                @if ($deal->isMeasurementOverdue())
                                    <p class="gravit-card__alert" title="Замер {{ $deal->measured_at->format('d.m.Y H:i') }} прошёл, сделка не продвинулась">
                                        Замер просрочен {{ $deal->measurementOverdueDays() }} дн.
                                    </p>
                                @endif

                                @if ($deal->isShipmentBlocked())
                                    <p class="gravit-card__alert" title="{{ $deal->shipment_block_reason }}">
                                        Отгрузка заблокирована{{ $deal->shipment_block_reason ? ': '.$deal->shipment_block_reason : '' }}
                                    </p>
                                @endif

                                @if ($waiting)
                                    <p class="gravit-card__waiting" title="Дальше сделку переведёт производство">
                                        Ждёт завод{{ $waiting->currentStage ? ': '.$waiting->currentStage->name : '' }}
                                    </p>
                                @endif

                                <div class="gravit-card__actions">
                                    @if ($canMove)
                                    <div class="gravit-card__move">
                                        <button
                                            type="button"
                                            class="gravit-move"
                                            @if ($previousStage && ! $waiting)
                                                wire:click="moveDeal({{ $deal->id }}, {{ $previousStage->id }})"
                                                title="Вернуть на «{{ $previousStage->name }}»"
                                                aria-label="Вернуть на «{{ $previousStage->name }}»"
                                            @else
                                                disabled aria-label="Это первый этап"
                                            @endif
                                        >←</button>

                                        {{-- Последний этап цеха: стрелке некуда вести, наряд закрывается кнопкой «Готово». --}}
                                        @if ($deal->isFactoryOrder() && ($stage->completes_production || ! $nextStage))
                                            <button
                                                type="button"
                                                class="gravit-move gravit-move--finish"
                                                wire:click="mountAction('completeStage', { dealId: {{ $deal->id }} })"
                                                wire:loading.attr="disabled"
                                                title="Закрыть этап «{{ $stage->name }}» и завершить наряд"
                                                aria-label="Закрыть этап «{{ $stage->name }}» и завершить наряд"
                                            >Готово ✓</button>
                                        @else
                                            <button
                                                type="button"
                                                class="gravit-move"
                                                @if ($nextStage && ! $waiting)
                                                    wire:click="moveDeal({{ $deal->id }}, {{ $nextStage->id }})"
                                                    title="Перевести на «{{ $nextStage->name }}»"
                                                    aria-label="Перевести на «{{ $nextStage->name }}»"
                                                @else
                                                    disabled aria-label="Это последний этап"
                                                @endif
                                            >→</button>
                                        @endif
                                    </div>
                                    @endif

                                    @if ($canOpen)
                                        <a href="{{ \App\Filament\Resources\Deals\DealResource::cardUrl($deal) }}"
                                           class="gravit-card__link">Открыть</a>
                                    @endif
                                    <a href="{{ route('track.show', $deal->qr_code_hash) }}" target="_blank"
                                       class="gravit-card__link gravit-card__link--muted">Клиенту</a>
                                </div>
                            </article>
                        @empty
                            <p class="gravit-column__empty">Пусто — переносите карточки кнопками «←&nbsp;→» или мышкой</p>
                        @endforelse

                        @if ($hidden > 0)
                            <button type="button" wire:click="loadMore({{ $stage->id }})" class="gravit-more">
                                Показать ещё {{ $hidden }}
                            </button>
                        @endif
                    </div>
                </section>
            @empty
                <div class="gravit-empty">
                    <p>В этой воронке нет активных этапов.</p>
                    <a href="{{ \App\Filament\Resources\FactoryStages\FactoryStageResource::getUrl() }}">Настроить этапы →</a>
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
