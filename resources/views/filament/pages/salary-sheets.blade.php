{{-- Зарплата: месяц, итоги, ведомость по сотрудникам. --}}
<x-filament-panels::page>
    @php($t = $this->totals())

    <div class="gravit-toolbar">
        <select wire:model.live="month" class="gravit-select">
            @foreach ($this->monthOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="gravit-bento gravit-bento--compact">
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">К выплате за месяц</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($t['total']) }}</p>
            <p class="gravit-tile__hint">{{ $t['count'] }} {{ \App\Support\Plural::choose((int) $t['count'], 'сотрудник', 'сотрудника', 'сотрудников') }}@if ($t['drafts'] > 0) · черновиков: {{ $t['drafts'] }}@endif</p>
        </div>
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Выплачено</p>
            <p class="gravit-tile__value" style="color: rgb(21 128 61);">{{ \App\Support\Money::format($t['paid']) }}</p>
        </div>
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Осталось выплатить</p>
            <p class="gravit-tile__value" @if ($t['remaining'] > 0) style="color: rgb(185 28 28);" @endif>{{ \App\Support\Money::format($t['remaining']) }}</p>
            <p class="gravit-tile__hint">выплата создаёт расход «Зарплата» и списание со счёта</p>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
