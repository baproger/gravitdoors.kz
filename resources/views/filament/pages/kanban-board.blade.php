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
        <div class="gravit-toolbar">
            <label class="gravit-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="gravit-search__icon" />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Номер, клиент, телефон…"
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
        </div>

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
                            @if ($showMoney && $sum > 0)
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

                            <article
                                draggable="true"
                                @dragstart="start($event, {{ $deal->id }})"
                                @dragend="draggingId = null; overStage = null"
                                :class="draggingId === {{ $deal->id }} && 'gravit-card--dragging'"
                                class="gravit-card"
                                wire:key="deal-{{ $deal->id }}"
                            >
                                <div class="gravit-card__top">
                                    <span class="gravit-card__number">{{ $deal->number }}</span>
                                    @if ($deal->hours_on_stage >= 1)
                                        <span class="gravit-card__timer">⏱ {{ $deal->hours_on_stage }} ч</span>
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
                                            {{ $configs->count() }} позиции · {{ $deal->doorsCount() }} шт
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
                                            'gravit-card__due--late' => $deal->due_date->isPast(),
                                        ])>
                                            {{ $deal->due_date->format('d.m') }}
                                        </span>
                                    @endif
                                </div>

                                <div class="gravit-card__actions">
                                    <div class="gravit-card__move">
                                        <button
                                            type="button"
                                            class="gravit-move"
                                            @if ($previousStage)
                                                wire:click="moveDeal({{ $deal->id }}, {{ $previousStage->id }})"
                                                title="Вернуть на «{{ $previousStage->name }}»"
                                                aria-label="Вернуть на «{{ $previousStage->name }}»"
                                            @else
                                                disabled aria-label="Это первый этап"
                                            @endif
                                        >←</button>

                                        <button
                                            type="button"
                                            class="gravit-move"
                                            @if ($nextStage)
                                                wire:click="moveDeal({{ $deal->id }}, {{ $nextStage->id }})"
                                                title="Перевести на «{{ $nextStage->name }}»"
                                                aria-label="Перевести на «{{ $nextStage->name }}»"
                                            @else
                                                disabled aria-label="Это последний этап"
                                            @endif
                                        >→</button>
                                    </div>

                                    <a href="{{ \App\Filament\Resources\Deals\DealResource::getUrl('edit', ['record' => $deal]) }}"
                                       class="gravit-card__link">Открыть</a>
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
