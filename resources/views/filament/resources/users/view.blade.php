{{-- Карточка сотрудника: показатели, активность, зарплата и что человек сделал. --}}
<x-filament-panels::page>
    @php
        $employee = $this->employee();
        $money = $this->canSeeMoney();
        $payroll = $this->payroll();
        $activity = $this->weeklyActivity();
        $peak = max(1, collect($activity)->max(fn (array $day): int => $day['events'] + $day['stages']));
    @endphp

    <div class="gravit-profile">
        {{-- Левая колонка --}}
        <div class="gravit-profile__main">

            {{-- Показатели --}}
            <section class="gravit-tile">
                <p class="gravit-tile__label">Показатели</p>

                <div class="gravit-rings">
                    @foreach ($this->metrics() as $metric)
                        @php
                            $ratio = $metric['total'] > 0 ? min(1, $metric['value'] / $metric['total']) : 0;
                            $dash = round($ratio * 100, 1);
                        @endphp

                        <div class="gravit-ring" data-color="{{ $metric['color'] }}">
                            <div class="gravit-ring__chart">
                                <svg viewBox="0 0 36 36" class="gravit-ring__svg" aria-hidden="true">
                                    <circle class="gravit-ring__track" cx="18" cy="18" r="15.9155" />
                                    @if ($dash > 0)
                                        <circle class="gravit-ring__value" cx="18" cy="18" r="15.9155"
                                                stroke-dasharray="{{ $dash }} 100" />
                                    @endif
                                </svg>
                                <div class="gravit-ring__center">
                                    <span class="gravit-ring__num">{{ $metric['value'] }}</span>
                                    <span class="gravit-ring__of">/{{ $metric['total'] }}</span>
                                </div>
                            </div>
                            <p class="gravit-ring__label">{{ $metric['label'] }}</p>
                            <p class="gravit-ring__hint">{{ $metric['hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Активность --}}
            <section class="gravit-tile">
                <div class="gravit-tile__head">
                    <p class="gravit-tile__label">Активность за неделю</p>
                    <div class="gravit-legend">
                        <span><i class="gravit-dot gravit-dot--primary"></i> действий: {{ collect($activity)->sum('events') }}</span>
                        <span><i class="gravit-dot gravit-dot--warning"></i> этапов цеха: {{ collect($activity)->sum('stages') }}</span>
                    </div>
                </div>

                <div class="gravit-bars">
                    @foreach ($activity as $day)
                        @php($total = $day['events'] + $day['stages'])
                        <div class="gravit-bar" title="{{ $day['day'] }}: {{ $day['events'] }} действий, {{ $day['stages'] }} этапов">
                            <div class="gravit-bar__track">
                                @if ($day['stages'] > 0)
                                    <div class="gravit-bar__fill gravit-bar__fill--warning"
                                         style="height: {{ round($day['stages'] / $peak * 100) }}%"></div>
                                @endif
                                @if ($day['events'] > 0)
                                    <div class="gravit-bar__fill gravit-bar__fill--primary"
                                         style="height: {{ round($day['events'] / $peak * 100) }}%"></div>
                                @endif
                            </div>
                            <span @class(['gravit-bar__day', 'gravit-bar__day--today' => $day['today']])>{{ $day['day'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Зарплата --}}
            @if ($money)
                <section class="gravit-tile">
                    <div class="gravit-tile__head">
                        <p class="gravit-tile__label">Зарплата</p>
                        <select wire:model.live="month" class="gravit-select gravit-select--sm">
                            @foreach ($this->monthOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <p class="gravit-tile__hint">К выплате за месяц</p>
                    <p class="gravit-tile__value">{{ \App\Support\Money::format($payroll['total']) }}</p>

                    @php($salaryShare = $payroll['total'] > 0 ? round($payroll['salary'] / $payroll['total'] * 100) : 100)

                    <div class="gravit-split">
                        <div class="gravit-split__salary" style="width: {{ $salaryShare }}%"></div>
                    </div>

                    <div class="gravit-legend gravit-legend--wide">
                        <span><i class="gravit-dot gravit-dot--primary"></i> Оклад {{ \App\Support\Money::format($payroll['salary']) }}</span>
                        <span><i class="gravit-dot gravit-dot--success"></i> Сдельно {{ \App\Support\Money::format($payroll['piecework']) }}</span>
                    </div>
                    <p class="gravit-tile__hint">Закрыто этапов цеха: <strong>{{ $payroll['stages'] }}</strong></p>
                </section>
            @endif

            {{-- Сделки и наряды --}}
            <div class="gravit-profile__pair">
                <section class="gravit-tile">
                    <p class="gravit-tile__label">Сделки <span class="gravit-count">{{ $this->deals()->count() }}</span></p>

                    <ul class="gravit-list">
                        @forelse ($this->deals() as $deal)
                            <li class="gravit-list__row">
                                <a href="{{ \App\Filament\Resources\Deals\DealResource::getUrl('edit', ['record' => $deal]) }}"
                                   class="gravit-list__main">
                                    <span class="gravit-list__title">{{ $deal->number }} · {{ $deal->clientTitle() }}</span>
                                    <span class="gravit-list__sub">
                                        {{ $deal->currentStage?->name ?? '—' }}
                                        @if ($deal->due_date) · срок {{ $deal->due_date->format('d.m.Y') }} @endif
                                    </span>
                                </a>
                                @if ($money)
                                    <span class="gravit-list__value">{{ \App\Support\Money::format($deal->total_price) }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="gravit-list__empty">Сделок пока нет</li>
                        @endforelse
                    </ul>
                </section>

                <section class="gravit-tile">
                    <p class="gravit-tile__label">Работа в цеху <span class="gravit-count">{{ $this->workshopLogs()->count() }}</span></p>

                    <ul class="gravit-list">
                        @forelse ($this->workshopLogs() as $log)
                            <li class="gravit-list__row">
                                <span class="gravit-list__main">
                                    <span class="gravit-list__title">
                                        {{ $log->deal?->number }} · {{ $log->stage->name }}
                                    </span>
                                    <span class="gravit-list__sub">
                                        {{ $log->status->getLabel() }}
                                        @if ($log->finished_at) · {{ $log->finished_at->format('d.m.Y') }} @endif
                                        @if ($log->durationHours() !== null) · {{ $log->durationHours() }} ч @endif
                                    </span>
                                </span>
                                @if ($money && (float) $log->payout > 0)
                                    <span class="gravit-list__value">{{ \App\Support\Money::format($log->payout) }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="gravit-list__empty">В цеху пока не работал</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            {{-- Последние действия --}}
            <section class="gravit-tile">
                <p class="gravit-tile__label">Последние действия</p>

                <ul class="gravit-list">
                    @forelse ($this->recentEvents() as $event)
                        <li class="gravit-list__row">
                            <span class="gravit-list__main">
                                <span class="gravit-list__title">{{ $event->description }}</span>
                                <span class="gravit-list__sub">
                                    {{ $event->department->getLabel() }} · {{ $event->deal?->number }} ·
                                    {{ $event->created_at->format('d.m.Y H:i') }}
                                </span>
                            </span>
                        </li>
                    @empty
                        <li class="gravit-list__empty">Действий пока нет</li>
                    @endforelse
                </ul>
            </section>
        </div>

        {{-- Правая колонка: сам сотрудник --}}
        <aside class="gravit-profile__side">
            <section class="gravit-tile gravit-person">
                @if ($employee->getFilamentAvatarUrl())
                    <img src="{{ $employee->getFilamentAvatarUrl() }}" alt="{{ $employee->name }}" class="gravit-person__avatar">
                @else
                    <div class="gravit-person__avatar gravit-person__avatar--initials">{{ $employee->initials() }}</div>
                @endif

                <p class="gravit-person__name">{{ $employee->name }}</p>
                <p class="gravit-person__role">{{ $employee->role->getLabel() }}</p>
                <a href="mailto:{{ $employee->email }}" class="gravit-person__email">{{ $employee->email }}</a>

                <dl class="gravit-person__facts">
                    @if ($employee->phone)
                        <div><dt>Телефон</dt><dd><a href="tel:{{ preg_replace('/\D+/', '', $employee->phone) }}">{{ $employee->phone }}</a></dd></div>
                    @endif
                    @if ($employee->hired_at)
                        <div><dt>В компании с</dt><dd>{{ $employee->hired_at->format('d.m.Y') }} · {{ $employee->tenure() }}</dd></div>
                    @endif
                    @if ($employee->birth_date)
                        <div><dt>День рождения</dt><dd>{{ $employee->birth_date->format('d.m.Y') }}</dd></div>
                    @endif
                    @if ($money)
                        <div><dt>Оклад</dt><dd>{{ \App\Support\Money::format($employee->salary) }}</dd></div>
                    @endif
                    <div><dt>Доступ</dt><dd>{{ $employee->is_active ? 'Открыт' : 'Закрыт' }}</dd></div>
                </dl>

                @if (auth()->id() === $employee->id)
                    <a href="{{ \Filament\Facades\Filament::getProfileUrl() }}" class="gravit-person__action">
                        Изменить фото и контакты
                    </a>
                @endif
            </section>
        </aside>
    </div>
</x-filament-panels::page>
