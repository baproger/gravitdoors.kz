{{-- Поступления: итоги сверху, список платежей снизу. --}}
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
            <p class="gravit-tile__label">Поступило</p>
            <p class="gravit-tile__value" style="color: rgb(21 128 61);">{{ \App\Support\Money::format($t['total']) }}</p>
            <p class="gravit-tile__hint">{{ (int) $t['count'] }} {{ \App\Support\Plural::choose((int) $t['count'], 'платёж', 'платежа', 'платежей') }}</p>
        </div>
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Наличными — в кассу</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($t['cash']) }}</p>
        </div>
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">Безнал — в банк</p>
            <p class="gravit-tile__value">{{ \App\Support\Money::format($t['bank']) }}</p>
            <p class="gravit-tile__hint">карта, Kaspi, перевод</p>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
