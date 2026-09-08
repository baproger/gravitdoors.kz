<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $idle = $this->idleWorkers();
    @endphp

    <div class="gravit-toolbar">
        <select wire:model.live="month" class="gravit-select">
            @foreach ($this->monthOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="gravit-bento">
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Начислено за месяц</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($this->total()) }}</p>
            <p class="gravit-tile__hint">{{ $rows->count() }} сотрудников с выработкой</p>
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Закрыто этапов</p>
            <p class="gravit-tile__value">{{ $rows->sum('stages') }}</p>
            <p class="gravit-tile__hint">суммарно по цеху</p>
        </div>

        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Часы: факт / норматив</p>
            <p class="gravit-tile__value">{{ $rows->sum('hours') }} / {{ $rows->sum('planned') }}</p>
            <p class="gravit-tile__hint">
                @if ($rows->sum('planned') > 0 && $rows->sum('hours') > $rows->sum('planned'))
                    цех идёт дольше норматива
                @else
                    в пределах норматива
                @endif
            </p>
        </div>

        <div class="gravit-tile">
            <p class="gravit-tile__label">По сотрудникам</p>

            <div class="gravit-lines" style="margin-top: 0.75rem;">
                @forelse ($rows as $row)
                    <div class="gravit-line">
                        <span style="color: var(--gravit-text); font-weight: 600;">{{ $row['worker']?->name ?? '—' }}</span>
                        <span>
                            <span style="color: var(--gravit-muted); font-weight: 400;">
                                {{ $row['stages'] }} эт. · {{ $row['hours'] }} ч
                            </span>
                            &nbsp;&nbsp;{{ \App\Support\Money::format($row['payout']) }}
                        </span>
                    </div>
                @empty
                    <p class="gravit-tile__hint">За этот месяц закрытых этапов нет.</p>
                @endforelse

                @if ($rows->isNotEmpty())
                    <div class="gravit-line gravit-line--total">
                        <span>Итого</span>
                        <span>{{ \App\Support\Money::format($this->total()) }}</span>
                    </div>
                @endif
            </div>

            @if ($idle->isNotEmpty())
                <p class="gravit-tile__hint" style="margin-top: 1rem;">
                    Без выработки в этом месяце: {{ $idle->pluck('name')->implode(', ') }}
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
