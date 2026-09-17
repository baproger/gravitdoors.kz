{{-- Счета: вкладки по состоянию оплаты и таблица сделок. --}}
<x-filament-panels::page>
    <div class="od">
        <div class="gp-tabs" role="tablist" aria-label="Состояние оплаты">
            @foreach ($this->modes() as $key => $tab)
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('mode', @js($key))"
                    aria-selected="{{ $mode === $key ? 'true' : 'false' }}"
                    class="gp-tab {{ $mode === $key ? 'gp-tab--active' : '' }}"
                    title="{{ \App\Support\Money::format($tab['sum']) }}"
                >
                    <span>{{ $tab['label'] }}</span>
                    <span class="gp-tab__count {{ $key === 'overdue' && $tab['count'] > 0 ? 'od-count--hot' : '' }}">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>

        @php($current = $this->modes()[$mode] ?? null)
        @if ($current)
            <p class="gravit-tile__hint" style="margin: 0.5rem 0 0.75rem;">
                {{ $current['label'] }}: {{ $current['count'] }} · {{ $mode === 'paid' || $mode === 'all' ? 'сумма договоров' : 'остаток к оплате' }} {{ \App\Support\Money::format($current['sum']) }}
            </p>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
