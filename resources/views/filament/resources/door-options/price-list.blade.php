{{-- Прайс конфигуратора: сводка-bento, поиск с фильтрами и карточка на каждую группу опций. --}}
<x-filament-panels::page>
    @php
        $canEdit = $this->canEdit();
        $groups = $this->groups();
        $counts = $this->filterCounts();
        $filtering = trim($search) !== '' || $filter !== 'all';
    @endphp

    <div class="pl">
        {{-- Сводка --}}
        <section class="pl-bento" aria-label="Сводка по прайсу">
            @foreach ($this->summary() as $tile)
                <div class="pl-tile pl-span-{{ $tile['span'] }} {{ $tile['hero'] ? 'pl-tile--hero' : '' }}">
                    <p class="pl-tile__label">{{ $tile['label'] }}</p>
                    <p class="pl-tile__value">{{ $tile['value'] }}</p>
                    <p class="pl-tile__hint">{{ $tile['hint'] }}</p>
                </div>
            @endforeach
        </section>

        {{-- Поиск и фильтры --}}
        <div class="pl-toolbar">
            <label class="pl-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="pl-search__icon" />
                <input
                    type="search"
                    class="pl-search__input"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Найти позицию или код"
                    aria-label="Поиск по прайсу"
                />
            </label>

            <div class="pl-filters" role="group" aria-label="Фильтр">
                @foreach ($this->filters() as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('filter', @js($key))"
                        aria-pressed="{{ $filter === $key ? 'true' : 'false' }}"
                        class="pl-filter {{ $filter === $key ? 'pl-filter--on' : '' }}"
                    >
                        {{ $label }}
                        <span class="pl-filter__count">{{ $counts[$key] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            @if ($canEdit)
                <button type="button" class="pl-primary" wire:click="mountAction('createOption')">
                    <x-filament::icon icon="heroicon-m-plus" class="pl-primary__icon" />
                    Новая позиция
                </button>
            @endif
        </div>

        @if ($groups === [])
            <div class="pl-nothing">
                Ничего не найдено{{ trim($search) !== '' ? ' по запросу «'.trim($search).'»' : '' }}.
                <button type="button" class="pl-link" wire:click="$set('search', ''); $set('filter', 'all')">Сбросить</button>
            </div>
        @endif

        {{-- Группы --}}
        <div class="pl-grid">
            @foreach ($groups as $group)
                {{-- Блочная форма, а не @php(...): в одном шаблоне с блоками @php … @endphp
                     однострочный вызов Blade принимает за начало блока и глотает разметку до @endphp. --}}
                @php $category = $group['category']; @endphp

                <section class="pl-card pl-span-{{ $group['span'] }}" data-accent="{{ $group['accent'] }}" aria-labelledby="pl-{{ $category->value }}">
                    <header class="pl-card__head">
                        <span class="pl-card__glyph" aria-hidden="true">
                            <x-filament::icon :icon="$group['icon']" class="pl-card__icon" />
                        </span>

                        <div class="pl-card__titles">
                            <h2 class="pl-card__title" id="pl-{{ $category->value }}">
                                {{ $category->getLabel() }}
                                <span class="pl-card__count">{{ $group['total'] }}</span>
                            </h2>
                            <p class="pl-card__meta">{{ $group['meta'] }}</p>
                        </div>

                        @if ($canEdit)
                            <button
                                type="button"
                                class="pl-card__add"
                                wire:click="mountAction('createOption', { category: @js($category->value) })"
                                title="Добавить позицию в «{{ $category->getLabel() }}»"
                                aria-label="Добавить позицию в «{{ $category->getLabel() }}»"
                            >
                                <x-filament::icon icon="heroicon-m-plus" class="pl-card__add-icon" />
                            </button>
                        @endif
                    </header>

                    <ul class="pl-list">
                        @forelse ($group['options'] as $option)
                            @php
                                $usage = $this->usageOf($option);
                                $stock = $this->stockNote($option);
                                $isAdditional = $category === \App\Enums\DoorOptionCategory::Additional;
                            @endphp

                            <li
                                class="pl-row {{ $option->is_active ? '' : 'pl-row--off' }}"
                                wire:key="option-{{ $option->id }}-{{ md5($option->label.'|'.$option->price.'|'.$option->sort.'|'.(int) $option->is_active.'|'.(int) $option->is_default.'|'.$usage) }}"
                            >
                                <div class="pl-row__main">
                                    <span class="pl-row__label">
                                        {{ $option->label }}
                                        @unless ($option->is_active)
                                            <span class="pl-tag">снята с продажи</span>
                                        @endunless
                                    </span>
                                    @php
                                        $usageText = $usage > 0 ? 'в '.$usage.' '.\App\Support\Plural::choose($usage, 'двери', 'дверях', 'дверях') : null;
                                    @endphp
                                    {{-- Одна строка: в узкой карточке код, «в N дверях» и материал переносились
                                         на 3–4 строки. Материал обрезается многоточием, полный текст — в подсказке. --}}
                                    <span class="pl-row__sub" title="{{ collect([$option->code, $usageText, $stock ? 'со склада: '.$stock : null])->filter()->implode(' · ') }}">
                                        <span class="pl-row__code">{{ $option->code }}</span>
                                        @if ($usageText)
                                            <span class="pl-row__usage">· {{ $usageText }}</span>
                                        @endif
                                        @if ($stock)
                                            <span class="pl-row__stock">· {{ $stock }}</span>
                                        @endif
                                    </span>
                                </div>

                                <div class="pl-row__price">
                                    <span class="pl-row__amount">{{ \App\Support\Money::format((float) $option->price) }}</span>
                                    <span class="pl-row__unit">{{ $this->unit($option->price_type) }}</span>
                                </div>

                                <div class="pl-row__flags">
                                    @unless ($isAdditional)
                                        <button
                                            type="button"
                                            class="pl-star {{ $option->is_default ? 'is-on' : '' }}"
                                            @if ($canEdit) wire:click="toggleDefault({{ $option->id }})" @else disabled @endif
                                            title="{{ $option->is_default ? 'Вариант по умолчанию в новой двери' : 'Сделать вариантом по умолчанию' }}"
                                            aria-label="{{ $option->is_default ? 'Вариант по умолчанию' : 'Сделать вариантом по умолчанию' }}"
                                            aria-pressed="{{ $option->is_default ? 'true' : 'false' }}"
                                        >★</button>
                                    @endunless

                                    <button
                                        type="button"
                                        role="switch"
                                        class="pl-switch {{ $option->is_active ? 'is-on' : '' }}"
                                        @if ($canEdit) wire:click="toggleActive({{ $option->id }})" @else disabled @endif
                                        aria-checked="{{ $option->is_active ? 'true' : 'false' }}"
                                        title="{{ $option->is_active ? 'В продаже — нажмите, чтобы снять' : 'Снята с продажи — нажмите, чтобы вернуть' }}"
                                        aria-label="В продаже"
                                    ><span class="pl-switch__knob"></span></button>
                                </div>

                                @if ($canEdit)
                                    <div class="pl-row__actions">
                                        <button
                                            type="button"
                                            class="gp-icon-btn"
                                            wire:click="mountAction('editOption', { option: {{ $option->id }} })"
                                            title="Изменить"
                                            aria-label="Изменить «{{ $option->label }}»"
                                        >
                                            <x-filament::icon icon="heroicon-m-pencil-square" class="gp-icon" />
                                        </button>

                                        <div class="gp-menu" x-data="{ open: false }" x-on:keydown.escape="open = false">
                                            <button type="button" class="gp-icon-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" title="Ещё" aria-label="Ещё действия">⋯</button>
                                            <div class="gp-menu__list" x-cloak x-show="open" x-on:click.outside="open = false" x-transition.opacity>
                                                <button type="button" class="gp-menu__item" wire:click="moveOption({{ $option->id }}, -1)" x-on:click="open = false" @disabled($loop->first)>↑ Выше</button>
                                                <button type="button" class="gp-menu__item" wire:click="moveOption({{ $option->id }}, 1)" x-on:click="open = false" @disabled($loop->last)>↓ Ниже</button>
                                                <button type="button" class="gp-menu__item gp-menu__item--danger" wire:click="mountAction('deleteOption', { option: {{ $option->id }} })" x-on:click="open = false">Удалить</button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @empty
                            <li class="pl-empty">
                                В группе пока пусто.
                                @if ($canEdit)
                                    <button type="button" class="pl-link" wire:click="mountAction('createOption', { category: @js($category->value) })">Добавить позицию</button>
                                @endif
                            </li>
                        @endforelse
                    </ul>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
