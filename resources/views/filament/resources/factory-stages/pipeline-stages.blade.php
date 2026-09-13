{{--
    Настройка воронок: этапы строками в порядке воронки.

    Перетаскивание — нативный HTML5 drag & drop на Alpine, как на канбане.
    Тащить можно только за ручку «⋮⋮»: если бы вся строка была draggable,
    в поле названия нельзя было бы выделить текст мышью. Кнопки ↑ ↓ делают
    то же самое на телефоне, где drag-события не приходят.
--}}
<x-filament-panels::page>
    @php
        $pipelineType = $this->pipelineType();
        $isSales = $pipelineType === \App\Enums\PipelineType::Sales;
        $stages = $this->stages();
        $counts = $this->stageCounts();
        $automation = $this->automationStage();
        $symbol = config('gravit.currency.symbol');
        $nameMax = \App\Services\PipelineStageService::NAME_MAX;

        $groups = [
            [
                'final' => false,
                'title' => 'Этапы в работе',
                'hint' => 'Сделка проходит их по порядку, сверху вниз.',
                'items' => $stages->filter(fn ($s) => ! $s->is_final)->values(),
                'model' => 'newStageName',
                'placeholder' => $isSales ? 'Новый этап, например «Выезд на объект»' : 'Новая операция, например «Сборка коробки»',
                'empty' => 'Добавьте первый этап.',
            ],
            [
                'final' => true,
                'title' => 'Завершающие этапы',
                'hint' => 'Финиш воронки: сюда сделка попадает в самом конце.',
                'items' => $stages->filter(fn ($s) => $s->is_final)->values(),
                'model' => 'newFinalStageName',
                'placeholder' => 'Новый завершающий этап',
                'empty' => 'Завершающих этапов нет — воронка заканчивается последним рабочим.',
            ],
        ];
    @endphp

    <div
        class="gp"
        x-data="{
            ready: null,
            dragId: null,
            overId: null,
            start(event, id) {
                this.dragId = id
                event.dataTransfer.effectAllowed = 'move'
                event.dataTransfer.setData('text/plain', String(id))
            },
            finish() {
                this.dragId = null
                this.overId = null
                this.ready = null
            },
            dropOn(targetId) {
                const id = this.dragId
                this.finish()
                if (id && id !== targetId) $wire.dropStage(id, targetId)
            },
            dropEnd() {
                const id = this.dragId
                this.finish()
                if (id) $wire.dropStage(id, null)
            },
        }"
        x-on:pointerup.window="if (! dragId) ready = null"
    >
        {{-- Воронки --}}
        <div class="gp-tabs" role="tablist" aria-label="Воронка">
            @foreach (\App\Enums\PipelineType::cases() as $type)
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('pipeline', @js($type->value))"
                    aria-selected="{{ $type === $pipelineType ? 'true' : 'false' }}"
                    class="gp-tab {{ $type === $pipelineType ? 'gp-tab--active' : '' }}"
                >
                    <x-filament::icon :icon="$type->getIcon()" class="gp-tab__icon" />
                    <span>{{ $type->getLabel() }}</span>
                    <span class="gp-tab__count">{{ $counts[$type->value] ?? 0 }}</span>
                </button>
            @endforeach
        </div>

        {{-- Воронка так, как её видят сотрудники --}}
        <section class="gp-flow" aria-label="Порядок этапов">
            <span class="gp-flow__label">Как видят сотрудники</span>
            <div class="gp-flow__chips">
                @foreach ($stages->filter(fn ($s) => $s->is_active)->values() as $stage)
                    <span class="gp-flow__chip {{ $stage->is_final ? 'gp-flow__chip--final' : '' }}" data-color="{{ $stage->color }}">
                        {{ $stage->name }}
                        @if ($isSales ? $stage->triggers_production : $stage->completes_production)
                            <span class="gp-flow__bolt" title="{{ $isSales ? 'Передаёт сделку в производство' : 'Завершает производство' }}">⚡</span>
                        @endif
                    </span>
                    @if (! $loop->last)
                        <span class="gp-flow__arrow" aria-hidden="true">›</span>
                    @endif
                @endforeach
            </div>
        </section>

        @if (! $automation)
            <div class="gp-alert" role="status">
                {{ $isSales
                    ? 'Ни один этап не передаёт сделку в производство — наряды на заводе сами создаваться не будут.'
                    : 'Ни один этап не завершает производство — сделки не будут сами переходить в «Готово к отгрузке».' }}
                Назначьте автоматику в меню «⋯» у нужного этапа.
            </div>
        @endif

        <div class="gp-groups">
            @foreach ($groups as $group)
                <section class="gp-group" x-on:dragover.prevent x-on:drop.prevent="dropEnd()">
                    <header class="gp-group__head">
                        <h2 class="gp-group__title">
                            {{ $group['title'] }}
                            <span class="gp-group__count">{{ $group['items']->count() }}</span>
                        </h2>
                        <p class="gp-group__hint">{{ $group['hint'] }}</p>
                    </header>

                    <ol class="gp-list">
                        @forelse ($group['items'] as $stage)
                            @php
                                $isFirst = $loop->first;
                                $isLast = $loop->last;
                                $isAutomation = $isSales ? $stage->triggers_production : $stage->completes_production;
                                $requirements = count($stage->required_fields ?? []);
                                $hours = (float) $stage->estimated_hours;
                                $cost = (float) $stage->operation_cost;
                            @endphp

                            <li
                                wire:key="stage-{{ $stage->id }}-{{ md5($stage->name.'|'.$stage->color.'|'.$stage->order.'|'.(int) $stage->is_active.'|'.(int) $isAutomation.'|'.$stage->deals_count) }}"
                                class="gp-row"
                                data-color="{{ $stage->color }}"
                                x-bind:draggable="ready === {{ $stage->id }} ? 'true' : 'false'"
                                x-on:dragstart="start($event, {{ $stage->id }})"
                                x-on:dragend="finish()"
                                x-on:dragover.prevent.stop="overId = {{ $stage->id }}"
                                x-on:drop.prevent.stop="dropOn({{ $stage->id }})"
                                x-bind:class="{
                                    'gp-row--dragging': dragId === {{ $stage->id }},
                                    'gp-row--over': overId === {{ $stage->id }} && dragId && dragId !== {{ $stage->id }},
                                    'gp-row--hidden': {{ $stage->is_active ? 'false' : 'true' }},
                                }"
                            >
                                <span
                                    class="gp-row__handle"
                                    title="Перетащите, чтобы поменять порядок"
                                    aria-hidden="true"
                                    x-on:pointerdown="ready = {{ $stage->id }}"
                                >⋮⋮</span>

                                <div class="gp-color" x-data="{ open: false }" x-on:keydown.escape="open = false">
                                    <button type="button" class="gp-color__dot" x-on:click="open = ! open" title="Цвет этапа" aria-label="Цвет этапа «{{ $stage->name }}»"></button>
                                    <div class="gp-color__palette" x-cloak x-show="open" x-on:click.outside="open = false" x-transition.opacity>
                                        @foreach ($this->colors() as $key => $label)
                                            <button
                                                type="button"
                                                class="gp-color__swatch {{ $stage->color === $key ? 'is-current' : '' }}"
                                                data-color="{{ $key }}"
                                                title="{{ $label }}"
                                                aria-label="{{ $label }}"
                                                wire:click="recolorStage({{ $stage->id }}, @js($key))"
                                                x-on:click="open = false"
                                            ></button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="gp-row__main">
                                    <input
                                        type="text"
                                        class="gp-row__name"
                                        value="{{ $stage->name }}"
                                        maxlength="{{ $nameMax }}"
                                        aria-label="Название этапа"
                                        x-data="{ original: @js($stage->name) }"
                                        x-on:keydown.enter.prevent="$el.blur()"
                                        x-on:keydown.escape.prevent="$el.value = original; $el.blur()"
                                        x-on:blur="if ($el.value.trim() !== original) $wire.renameStage({{ $stage->id }}, $el.value)"
                                        x-on:stage-name-reset.window="if ($event.detail.id === {{ $stage->id }}) $el.value = $event.detail.name"
                                    />
                                    @if (filled($stage->description))
                                        <p class="gp-row__desc" title="{{ $stage->description }}">{{ $stage->description }}</p>
                                    @endif
                                </div>

                                <div class="gp-row__meta">
                                    @unless ($stage->is_active)
                                        <span class="gp-chip gp-chip--muted">скрыт</span>
                                    @endunless

                                    @if ($isAutomation)
                                        <span class="gp-chip gp-chip--{{ $isSales ? 'warning' : 'success' }}" title="{{ $isSales ? 'Вход на этап открывает наряд на заводе' : 'Закрытие этапа переводит сделку в «Готово к отгрузке»' }}">
                                            ⚡ {{ $isSales ? 'в производство' : 'готово к отгрузке' }}
                                        </span>
                                    @endif

                                    @if (! $isSales && ($hours > 0 || $cost > 0))
                                        <span class="gp-chip" title="Норматив и сдельная оплата">
                                            {{ $hours > 0 ? rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ч' : '' }}{{ $hours > 0 && $cost > 0 ? ' · ' : '' }}{{ $cost > 0 ? number_format($cost, 0, ',', ' ').' '.$symbol : '' }}
                                        </span>
                                    @endif

                                    @if ($isSales && $requirements > 0)
                                        <span class="gp-chip" title="Без этих полей сделку на этап не пустят">
                                            {{ $requirements }} {{ \App\Support\Plural::choose($requirements, 'обязательное поле', 'обязательных поля', 'обязательных полей') }}
                                        </span>
                                    @endif

                                    <span class="gp-chip {{ $stage->deals_count > 0 ? 'gp-chip--strong' : 'gp-chip--muted' }}" title="Сейчас на этапе">
                                        {{ $stage->deals_count }} {{ $this->dealsWord($stage->deals_count) }}
                                    </span>
                                </div>

                                <div class="gp-row__actions">
                                    <button type="button" class="gp-icon-btn" wire:click="moveStage({{ $stage->id }}, -1)" @disabled($isFirst) title="Выше" aria-label="Переместить выше">↑</button>
                                    <button type="button" class="gp-icon-btn" wire:click="moveStage({{ $stage->id }}, 1)" @disabled($isLast) title="Ниже" aria-label="Переместить ниже">↓</button>
                                    <button type="button" class="gp-icon-btn" wire:click="mountAction('stageSettings', { stage: {{ $stage->id }} })" title="Настройки этапа" aria-label="Настройки этапа «{{ $stage->name }}»">
                                        <x-filament::icon icon="heroicon-m-cog-6-tooth" class="gp-icon" />
                                    </button>

                                    <div class="gp-menu" x-data="{ open: false }" x-on:keydown.escape="open = false">
                                        <button type="button" class="gp-icon-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" title="Ещё" aria-label="Ещё действия">⋯</button>
                                        <div class="gp-menu__list" x-cloak x-show="open" x-on:click.outside="open = false" x-transition.opacity>
                                            @if (! ($isSales && $stage->is_final))
                                                <button type="button" class="gp-menu__item" wire:click="toggleAutomation({{ $stage->id }})" x-on:click="open = false">
                                                    ⚡ {{ $isAutomation ? 'Выключить автоматику' : ($isSales ? 'Передаёт сделку в производство' : 'Завершает производство') }}
                                                </button>
                                            @endif
                                            <button type="button" class="gp-menu__item" wire:click="toggleFinal({{ $stage->id }})" x-on:click="open = false">
                                                {{ $stage->is_final ? 'Сделать рабочим этапом' : 'Сделать завершающим' }}
                                            </button>
                                            <button type="button" class="gp-menu__item" wire:click="toggleActive({{ $stage->id }})" x-on:click="open = false">
                                                {{ $stage->is_active ? 'Скрыть из воронки' : 'Показать в воронке' }}
                                            </button>
                                            <button type="button" class="gp-menu__item gp-menu__item--danger" wire:click="mountAction('deleteStage', { stage: {{ $stage->id }} })" x-on:click="open = false">
                                                Удалить этап
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        @empty
                            <li class="gp-empty">{{ $group['empty'] }}</li>
                        @endforelse
                    </ol>

                    <form class="gp-add" wire:submit="addStage({{ $group['final'] ? 'true' : 'false' }})">
                        <input
                            type="text"
                            class="gp-add__input"
                            wire:model="{{ $group['model'] }}"
                            placeholder="{{ $group['placeholder'] }}"
                            maxlength="{{ $nameMax }}"
                            aria-label="Название нового этапа"
                        />
                        <button type="submit" class="gp-add__btn">+ Добавить</button>
                    </form>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
